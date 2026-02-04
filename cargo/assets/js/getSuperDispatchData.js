// inside script tag
console.log('script root running');

function getCookieByName(name) {
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
        cookie = cookie.trim();
        if (cookie.startsWith(name + '=')) {
            return cookie.substring(name.length + 1);
        }
    }
    return null;
}
class SuperDispatch {
    static utils = {
        formatNumber: (value, options) => {
            if (value == null) value = 0;
            if (typeof value == 'string') value = parseFloat(value);
            if (Number.isFinite(value)) {
                try {
                    return value.toLocaleString('en-US', options);
                } catch (error) {
                    logError(error, 'formatNumber', { value, options });
                }
            }
            return String(value);
        },
        formatCurrency: (value, { maximumFractionDigits, minimumFractionDigits = 0 } = {}) => {
            if (maximumFractionDigits != null) {
                minimumFractionDigits = Math.min(maximumFractionDigits, minimumFractionDigits);
            }
            return SuperDispatch.utils.formatNumber(value, {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits,
                maximumFractionDigits
            });
        },
        round: (number, precision = 0) => {
            precision = +precision;
            let multiplier = Math.pow(10, precision);
            return (multiplier =
                precision >= 0
                    ? Math.round(number * multiplier) / multiplier
                    : Math.round(number / multiplier) * multiplier);
        },
        convert: (value, multiplier, precision) => {
            const converted = value * multiplier;
            return precision == null ? converted : SuperDispatch.utils.round(converted, precision);
        },
        kmToMile: (value, precision) => {
            return SuperDispatch.utils.convert(value, 1 / MILE_TO_KM_MULTIPLIER, precision);
        },
        convertPricePerKmToPricePerMile: (value, precision) => {
            return SuperDispatch.utils.convert(value, MILE_TO_KM_MULTIPLIER, precision);
        },

        formatVehicleType(input, { fallback = 'Unknown' } = {}) {
            if (!isValidVehicleType(input)) return fallback;

            switch (input) {
                case '2_door_coupe':
                    return 'Coupe (2 doors)';

                case 'pickup':
                    return 'Pickup (2 doors)';
                case '4_door_pickup':
                    return 'Pickup (4 doors)';

                case 'truck_daycab':
                    return 'Truck (daycab)';
                case 'truck_sleeper':
                    return 'Truck (with sleeper)';

                case 'trailer_bumper_pull':
                    return 'Trailer (Bumper Pull)';
                case 'trailer_gooseneck':
                    return 'Trailer (Gooseneck)';
                case 'trailer_5th_wheel':
                    return 'Trailer (5th Wheel)';

                case 'rv':
                case 'atv':
                case 'suv':
                    return input.toUpperCase();

                default:
                    return toStartCase(input);
            }

            function toStartCase(input) {
                return input
                    .replace(/_/g, ' ')
                    .replace(/\w\S*/g, word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase());
            }

            function isValidVehicleType(input) {
                const VEHICLE_TYPES = [
                    'sedan',
                    '2_door_coupe',
                    'suv',
                    'pickup',
                    '4_door_pickup',
                    'van',
                    'truck_daycab',
                    'truck_sleeper',
                    'motorcycle',
                    'boat',
                    'rv',
                    'heavy_machinery',
                    'freight',
                    'livestock',
                    'atv',
                    'trailer_bumper_pull',
                    'trailer_gooseneck',
                    'trailer_5th_wheel',
                    'other'
                ];
                return VEHICLE_TYPES.includes(input);
            }
        }
    };

    #vehicles = null;

    async init() {
        if (!this.#vehicles) {
            this.#vehicles = await this.fetchVehicles();
        }
        return this;
    }

    async fetchVehicles() {
        const response = await fetch(VEHICLES_URL);
        return response.json();
    }

    async getVehicles() {
        if (!this.#vehicles) {
            await this.init();
        }
        return this.#vehicles;
    }

    async getVehicleMakes() {
        const vehicles = await this.getVehicles();
        return Array.from(new Set(vehicles.map(x => x.make)));
    }

    async getVehicleModels(make) {
        const vehicles = await this.getVehicles();
        const models = vehicles.filter(x => x.make.toLowerCase() === make?.toLowerCase());
        return Array.from(new Set(models.map(x => x.model)));
    }

    async getVehicleType(make, model) {
        const vehicles = await this.getVehicles();
        const vehicle = vehicles.find(
            x =>
                x.make.toLowerCase() === make?.toLowerCase() && x.model.toLowerCase() === model?.toLowerCase()
        );
        return vehicle?.type;
    }

    getAveragePrice(list) {
        let totalPrice = 0;
        let totalPricePerMile = 0;

        if (!list.length) {
            return [0, 0];
        }

        for (let item of list) {
            totalPrice += item.price_per_vehicle;
            totalPricePerMile += item.price_per_mile_per_vehicle;
        }

        const averagePrice = totalPrice / list.length;
        const averagePricePerMile = totalPricePerMile / list.length;
        return [averagePrice, averagePricePerMile];
    }
}

const MILE_TO_KM_MULTIPLIER = 1.609344;
const base = 'https://pricing-insights.superdispatch.com';
const VEHICLES_URL = base + '/vehicles.json';

const SD = new SuperDispatch();
const sdPromise = SD.init();

async function getCoordinates(origin, destination) {
    return fetch('https://pricing-insights.superdispatch.com/api/internal/v1/get-coordinates', {
        headers: {
            accept: '*/*',
            'accept-language': 'ru-UA,ru;q=0.9',
            'content-type': 'application/json'
        },
        referrer:
            'https://pricing-insights.superdispatch.com/?utm_medium=Navigation%20Bar&utm_source=Web%20STMS',
        referrerPolicy: 'strict-origin-when-cross-origin',
        body: JSON.stringify({
            origin,
            destination
        }),
        method: 'POST',
        mode: 'cors',
        credentials: 'include'
    }).then(r => r.json());
}

async function calculateCarrierPay(payload) {
    return fetch('https://pricing-insights.superdispatch.com/api/internal/v1/predict-carrier-pay', {
        headers: {
            accept: '*/*',
            'accept-language': 'ru-UA,ru;q=0.9',
            'content-type': 'application/json'
        },
        referrer:
            'https://pricing-insights.superdispatch.com/?utm_medium=Navigation%20Bar&utm_source=Web%20STMS',
        referrerPolicy: 'strict-origin-when-cross-origin',
        body: JSON.stringify(payload),
        method: 'POST',
        mode: 'cors',
        credentials: 'include'
    }).then(r => r.json());
}

async function getRecentPostings(payload) {
    return fetch('https://pricing-insights.superdispatch.com/api/internal/v1/get-recent-postings', {
        headers: {
            accept: '*/*',
            'accept-language': 'ru-UA,ru;q=0.9',
            'content-type': 'application/json'
        },
        referrer:
            'https://pricing-insights.superdispatch.com/?utm_medium=Navigation%20Bar&utm_source=Web%20STMS',
        referrerPolicy: 'strict-origin-when-cross-origin',
        body: JSON.stringify(payload),
        method: 'POST',
        mode: 'cors',
        credentials: 'include'
    }).then(r => r.json());
}

async function getSuperDispatchDataRaw(pricingPayload) {
    console.log('🚀 ~ getSuperDispatchData ~ bats pricingPayload:', pricingPayload);
    const origin = {
        city: pricingPayload.origin.city,
        state: pricingPayload.origin.state,
        zip: pricingPayload.origin.zip
    };
    const destination = {
        city: pricingPayload.destination.city,
        state: pricingPayload.destination.state,
        zip: pricingPayload.destination.zip
    };

    let { meta, data: coordinates } = await getCoordinates(origin, destination);
    if (!meta.status === 'success') return null;

    Object.assign(origin, coordinates.origin);
    Object.assign(destination, coordinates.destination);

    const superDispatchP = {
        origin,
        destination,
        vehicles: [],
        trailer_type: pricingPayload.trailerType
    };

    // Simplify to handle just one vehicle
    const v = pricingPayload.vehicles[0];
    const type = (await SD.getVehicleType(v.make, v.model)) || v.vehicleType;
    superDispatchP.vehicles.push({
        $key: 'vehicle-key1',
        year: v.year,
        make: v.make,
        model: v.model,
        type,
        is_inoperable: !v.operable
    });

    console.log('🚀 ~ getSuperDispatchData ~ payload:', superDispatchP);

    const results = await Promise.allSettled([
        calculateCarrierPay(superDispatchP),
        getRecentPostings(superDispatchP)
    ]);

    // console.log('🚀 ~ getSuperDispatchData ~ results:', results);
    const [carrierPay, recentPostings] = results;

    if (results.every(x => x.status === 'rejected') && carrierPay.status === 'rejected') {
        throw carrierPay.reason;
    }

    if (carrierPay.status === 'rejected') {
        console.error('Failed to calculate carrier price. ' + carrierPay.reason.message);
    } else if (recentPostings.status === 'rejected') {
        console.error('Failed to load Super Loadboard postings. ' + recentPostings.reason.message);
    }

    return {
        carrierPay: carrierPay.status === 'fulfilled' ? carrierPay.value.data : null,
        recentPostings: recentPostings.status === 'fulfilled' ? recentPostings.value.data : []
    };
}

async function getSuperDispatchMarkup(pricingPayload) {
    try {
        const { carrierPay, recentPostings } = await getSuperDispatchDataRaw({ pricingPayload });
        console.log('🚀 ~ getSuperDispatchDataRAW ~ carrierPay, recentPostings:', carrierPay, recentPostings);
        const estimatedCarrierPrice = SuperDispatch.utils.formatCurrency(
            SuperDispatch.utils.round(carrierPay.total_price)
        );
        const pricePerMi =
            SuperDispatch.utils.formatCurrency(
                SuperDispatch.utils.convertPricePerKmToPricePerMile(
                    carrierPay.price_per_km / (pricingPayload.vehicles.length || 1),
                    2
                )
            ) + '/mi';
        const distance = SuperDispatch.utils.kmToMile(carrierPay.distance, 2) + 'mi';

        const [averagePrice, averagePricePerMile] = SD.getAveragePrice(recentPostings);

        const avgPricePerVehicle = SuperDispatch.utils.formatCurrency(averagePrice, {
            maximumFractionDigits: 0
        });

        const avgPricePerMileFormat = SuperDispatch.utils.formatCurrency(averagePricePerMile, {
            maximumFractionDigits: 2
        });

        /* --------------------------------- divider -------------------------------- */
        const predictedPrice = {
            Average: `Vehicle: ${avgPricePerVehicle}<br/>
Mile: ${avgPricePerMileFormat}`,
            Estimated: [
                `${estimatedCarrierPrice}<br/>${pricePerMi} · ${distance}`,
                'border: 1px solid green'
            ],
            Confidence: carrierPay.confidence + '%'
        };
        const predictedPriceInputsHtml = getInputsGridItems(predictedPrice);

        const sampelPricesHtml = `
        <thead>
            <tr>
                <th>Route</th>
                <th>Distance</th>
                <th>Name</th>
                <th>Type</th>
                <th>Inoperable</th>
                <th>Date Posted</th>
                <th>Date Available</th>
                <th>Final Price</th>
            </tr>
        </thead>
        <tbody>
        ${recentPostings
            .map(posting => {
                const { year, make, model, is_inoperable, type } = posting.vehicles[0];
                const title = [year, make, model].join(' ');
                const displayType = SuperDispatch.utils.formatVehicleType(type);
                const price = SuperDispatch.utils.formatCurrency(posting.price);
                const pricePerMi = SuperDispatch.utils.formatCurrency(
                    SuperDispatch.utils.convertPricePerKmToPricePerMile(posting.price_per_km, 2)
                );

                return `
                    <tr>
                        <td>
                            ${posting.origin.city}, ${posting.origin.state}
                            <br>
                            ${posting.destination.city}, ${posting.destination.state}
                        </td>
                        <td>${posting.distance_miles}</td>
                        <td class="ellipsis" title="${title}">${title}</td>
                        <td>${displayType}</td>
                        <td>${is_inoperable ? 'Yes' : 'No'}</td>
                        <td>${new Date(posting.posted_date).toLocaleDateString('en-US', {
                            dateStyle: 'short'
                        })}</td>
                        <td>${new Date(posting.pickup_date).toLocaleDateString('en-US', {
                            dateStyle: 'short'
                        })}</td>
                        <td>${price}<br>${pricePerMi} /mi</td>
                    </tr>
        `;
            })
            .join('\n')}
        </tbody>
    `;

        return {
            sampelPricesInputsHtml: predictedPriceInputsHtml,
            sampelPricesHtml,
            vehicleTypesError: ''
        };
    } catch (e) {
        console.error(e);

        return {
            sampelPricesHtml: '',
            vehicleTypesError: `<b>Super Dispatch error occurred!</b> Check the console to see the error message F12(CTRL+SHIFT+I).`
        };
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    console.log('script DOM loaded running');
    await sdPromise;
    console.log('script SD promise resolved');

    // const main = getCookieByName('formData');

    // sample data from cookies
    const main = {
        year: '2019',
        brand: 'Honda',
        model: 'Civic',
        condition: 'Operable',
        md_oc: 'Los Angeles',
        md_os: 'CA',
        md_oz: '90001',
        md_dc: 'New York',
        md_ds: 'NY',
        md_dz: '10001'
    };

    const pricingPayload = {
        origin: {
            city: main.md_oc || '',
            state: main.md_os,
            zip: main.md_oz || '',
            country: 'US'
        },
        destination: {
            city: main.md_dc || '',
            state: main.md_ds,
            zip: main.md_dz || '',
            country: 'US'
        },
        vehicles: [
            {
                year: main.year || '',
                make: main.brand || '',
                model: main.model || '',
                vehicleType: '',
                operable: main.condition !== 'Inoperable'
            }
        ],
        trailerType: 'Open'
    };

    // after quoteEstimate insert with plain js
    const { sampelPricesInputsHtml, sampelPricesHtml, vehicleTypesError } = await getSuperDispatchMarkup(
        pricingPayload
    );

    console.log('🚀 ~ getSuperDispatchData ~ sampelPricesInputsHtml:', sampelPricesInputsHtml);
    // const quoteEstimate = document.querySelector('.thnx-page-wrpr');
    // // after quoteEstimate insert with plain js
    // const container = document.createElement('div');
    // container.className = 'super-dispatch-container';
    // container.innerHTML = `
    //     <div class="super-dispatch-prices">
    //         <h2>Super Dispatch</h2>
    //         <div class="super-dispatch-prices__inputs">${sampelPricesInputsHtml}</div>
    //         <div class="super-dispatch-prices__table">${sampelPricesHtml}</div>
    //         <div class="super-dispatch-prices__error">${vehicleTypesError}</div>
    //     </div>
    // `;
    // quoteEstimate.appendChild(container);
    // const style = document.createElement('style');
    // style.innerHTML = `
    //     .super-dispatch-container {
    //         margin: 20px 0;
    //         padding: 20px;
    //         background-color: #f9f9f9;
    //         border-radius: 8px;
    //         box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    //     }
    //     .super-dispatch-prices__inputs {
    //         display: grid;
    //         grid-template-columns: repeat(3, 1fr);
    //         gap: 10px;
    //     }
    //     .super-dispatch-prices__table {
    //         margin-top: 20px;
    //     }
    //     .super-dispatch-prices__error {
    //         color: red;
    //         margin-top: 10px;
    //     }
    // `;
    // document.head.appendChild(style);
});
