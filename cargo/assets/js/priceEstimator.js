// inside script tag
console.log('script root running');

// Mock data mode settings
// 'FULL_DEMO' - uses mockData1 and mockData2 with no API requests
// 'WITH_REQUESTS' - only uses mockData1 for input data but makes real API requests
// 'DISABLED' - normal mode, uses real data from cookies and makes real API requests
let MOCK_DATA_MODE = 'DISABLED';

const mockData1 = {
  year: '2019',
  brand: 'Honda',
  model: 'Civic',
  condition: 'Operable',
  md_oc: 'Los Angeles',
  md_os: 'CA',
  md_oz: '90001',
  md_dc: 'New York',
  md_ds: 'NY',
  md_dz: '10001',
  bodytype: 'sedan'
};

const mockData2 = {
  carrierPay: {
    totalPrice: 1425,
    distance: 72,
    confidence: 86
  }
};
const MARKET_MARKUP = 30; // in percents
const BROKER_MARKUP = 250;

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
    formatCurrency: (
      value,
      { maximumFractionDigits, minimumFractionDigits = 0 } = {}
    ) => {
      if (maximumFractionDigits != null) {
        minimumFractionDigits = Math.min(
          maximumFractionDigits,
          minimumFractionDigits
        );
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
      return precision == null
        ? converted
        : SuperDispatch.utils.round(converted, precision);
    },
    kmToMile: (value, precision) => {
      return SuperDispatch.utils.convert(
        value,
        1 / MILE_TO_KM_MULTIPLIER,
        precision
      );
    },
    convertPricePerKmToPricePerMile: (value, precision) => {
      return SuperDispatch.utils.convert(
        value,
        MILE_TO_KM_MULTIPLIER,
        precision
      );
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
          .replace(
            /\w\S*/g,
            word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
          );
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
    const models = vehicles.filter(
      x => x.make.toLowerCase() === make?.toLowerCase()
    );
    return Array.from(new Set(models.map(x => x.model)));
  }

  async getVehicleTypes() {
    const vehicles = await this.getVehicles();
    return Array.from(new Set(vehicles.map(x => x.type)));
  }

  async getVehicleType(make, model, defaultType) {
    const vehicles = await this.getVehicles();
    const types = await this.getVehicleTypes();
    // console.log('🚀 ~ SuperDispatch ~ all getVehicleTypes:', types);

    // First try to find exact match for both make and model
    const exactMatch = vehicles.find(
      v =>
        v.make.toLowerCase() === make?.toLowerCase() &&
        v.model.toLowerCase() === model?.toLowerCase()
    );

    if (exactMatch?.type) {
      return exactMatch.type;
    }

    if (defaultType && types.includes(defaultType)) {
      return defaultType;
    }

    // If no exact match, try to find any vehicle with matching make
    const makeMatch = vehicles.find(
      v => v.make.toLowerCase() === make?.toLowerCase()
    );

    // Return the type from make match or default to 'sedan'
    return makeMatch?.type || 'sedan';
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
const PHP_PROXY_URL = 'https://theshipwise.com/proxy.php';
const VEHICLES_URL = PHP_PROXY_URL + '?path=vehicles';

const SD = new SuperDispatch();
const sdPromise = SD.init();

// Calculate distance from price and price_per_mile
function calculateDistanceFromPrice(price, pricePerMile) {
  if (!pricePerMile || pricePerMile === 0) return 0;
  return price / pricePerMile; // Returns distance in miles
}

async function getSuperDispatchDataRaw(pricingPayload, bodytype) {
  // Use mock data if in full demo mode
  if (MOCK_DATA_MODE === 'FULL_DEMO') {
    // console.log('Using mock data for carrier pay (FULL_DEMO mode)');
    return mockData2.carrierPay;
  }

  // Build payload for official API
  const v = pricingPayload.vehicles[0];
  const apiPayload = {
    pickup: {
      city: pricingPayload.origin.city,
      state: pricingPayload.origin.state,
      zip: pricingPayload.origin.zip
    },
    delivery: {
      city: pricingPayload.destination.city,
      state: pricingPayload.destination.state,
      zip: pricingPayload.destination.zip
    },
    trailer_type: pricingPayload.trailerType?.toLowerCase() || 'open',
    vehicles: [
      {
        type:
          (await SD.getVehicleType(v.make, v.model, bodytype)) ||
          v.vehicleType ||
          'sedan',
        is_inoperable: !v.operable,
        make: v.make,
        model: v.model,
        year: v.year
      }
    ]
  };

  // console.log('🚀 ~ getSuperDispatchDataRaw ~ payload:', apiPayload);

  const result = await callOfficialAPI(apiPayload);
  // console.log('🚀 ~ getSuperDispatchDataRaw ~ result:', result);

  if (!result || !result.data) {
    return null;
  }

  // Calculate distance in miles from price and price_per_mile
  const distanceMiles = calculateDistanceFromPrice(
    result.data.price,
    result.data.price_per_mile
  );

  return {
    totalPrice: result.data.price,
    distance: distanceMiles,
    confidence: result.data.confidence
  };
}

function tryAgainIfFailed(fn, retries = 3) {
  return new Promise((resolve, reject) => {
    const attempt = n => {
      fn()
        .then(resolve)
        .catch(error => {
          if (n === 1) {
            reject(error);
          } else {
            console.warn(`Retrying... (${retries - n + 1})`);
            attempt(n - 1);
          }
        });
    };
    attempt(retries);
  });
}

async function generateMarkup(pricingPayload, bodytype) {
  try {
    const carrierPay = await tryAgainIfFailed(() =>
      getSuperDispatchDataRaw(pricingPayload, bodytype)
    );
    // console.log('🚀 ~ generateMarkup ~ carrierPay:', carrierPay);

    // Add broker markup and market markup to base price
    const newPrice = carrierPay.totalPrice * (1 + MARKET_MARKUP / 100) + BROKER_MARKUP;
    // Calculate new price per mile with broker markup included
    const newPricePerMile = newPrice / carrierPay.distance;
    // console.log(newPrice, newPricePerMile);

    const estimatedCarrierPrice = SuperDispatch.utils.formatCurrency(
      SuperDispatch.utils.round(newPrice)
    );
    const pricePerMi =
      SuperDispatch.utils.formatCurrency(newPricePerMile) + '/mi';
    const distance = SuperDispatch.utils.round(carrierPay.distance) + 'mi';

    // console.log({ estimatedCarrierPrice, distance });
    /* --------------------------------- divider -------------------------------- */
    const confidence = calculateConfidence(carrierPay.confidence);

    return `
<span class="super-dispatch__price">${estimatedCarrierPrice}</span>
<span class="super-dispatch__price-per-vehicle">
    ${pricePerMi} · ${distance}
</span>
<div class="super-dispatch__price-confidence">
    <span style="color: ${confidence.color}; background-color: ${confidence.backgroundColor}">${carrierPay.confidence}%</span>
    <span>${confidence.text}</span>
</div>
`;
  } catch (e) {
    console.error(
      '<b>Super Dispatch error occurred!</b> Check the console to see the error message F12(CTRL+SHIFT+I).',
      e
    );
    return null;
  }
}

// Add a flag to track if displayData has run
let displayDataHasRun = false;

function display(markup) {
  // Set the flag to indicate displayData has run
  displayDataHasRun = true;

  // Remove loader if it exists
  removeLoader();

  if (!markup) {
    return;
  }

  const quoteEstimate = document.querySelector('.thnx-page-wrpr');

  // insert before quoteEstimate
  const container = document.createElement('div');
  container.className = 'super-dispatch__container';
  container.innerHTML = `
            <h2 class="super-dispatch__header">Fair Price Baseline</h2>
            <div class="super-dispatch__prices">${markup}</div>
    `;
  quoteEstimate.insertAdjacentElement('beforebegin', container);
}

function injectCss() {
  const style = document.createElement('style');

  style.innerHTML = `
        .super-dispatch__container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2em;
            font-family: Inter, sans-serif;
        }
        .super-dispatch__header {
          font-size: 24px;
          margin-bottom: 12px;
        }
        .super-dispatch__prices {
            display: flex;
            gap: 12px;
            align-items: end;
        }
        
        .super-dispatch__price {
            font-weight: 700;
            font-size: 32px;
            line-height: 1;
            color:  #FFFFFF;
        }
        .super-dispatch__price-per-vehicle > span {
    
        }
        .super-dispatch__price-confidence {
            font-family: Inter, sans-serif;
            font-weight: 700;
            font-size: 14px;
        }  
        .super-dispatch__price-confidence > span:first-child {
            border-radius: 4px;
            padding: 0 4px;
        }
        .loader {
          width: 48px;
          height: 48px;
          border: 5px solid #FFF;
          border-bottom-color: transparent;
          border-radius: 50%;
          display: inline-block;
          box-sizing: border-box;
          animation: rotation 1s linear infinite;
        }
        @keyframes rotation {
          0% {
              transform: rotate(0deg);
          }
          100% {
              transform: rotate(360deg);
          }
        } 
        .loader-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2em;
            font-family: Inter, sans-serif;
        }
    `;
  document.head.appendChild(style);
}

function addLoader() {
  // Don't create loader immediately - wait for 3 seconds
  setTimeout(() => {
    // Only show loader if displayData hasn't run yet
    if (!displayDataHasRun) {
      const quoteEstimate = document.querySelector('.thnx-page-wrpr');
      if (!quoteEstimate) return;

      const loaderContainer = document.createElement('div');
      loaderContainer.className = 'loader-container';
      loaderContainer.id = 'sd-loader-container';
      loaderContainer.innerHTML = '<span class="loader"></span>';

      quoteEstimate.insertAdjacentElement('beforebegin', loaderContainer);

      // Set timeout to remove loader after 5 seconds
      setTimeout(removeLoader, 5000);
    }
  }, 1500);
}

function removeLoader() {
  const loader = document.getElementById('sd-loader-container');
  if (loader) {
    loader.remove();
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  injectCss();
  addLoader();

  const leadName = decodeURIComponent(getCookieByName('md_lead_name'));
  // console.log("🚀 ~ leadName:", leadName)

  // Handle different mock data modes
  let main;
  if (leadName.trim().toLowerCase() === 'test no price') {
    MOCK_DATA_MODE = 'FULL_DEMO';
    main = mockData1;
  } else if (MOCK_DATA_MODE === 'DISABLED') {
    main = JSON.parse(decodeURIComponent(getCookieByName('formData')));
    // console.log('🚀 ~ on load ~ inputData (REAL DATA):', main);
  } else {
    main = mockData1;
    // console.log(`🚀 ~ on load ~ inputData (${MOCK_DATA_MODE} mode):`, main);
  }

  if (!main) {
    displayDataHasRun = true; // to cancel loader
    return;
  }

  await sdPromise;

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

  // console.log('🚀 ~ pricingPayload:', pricingPayload);
  const markup = await generateMarkup(
    pricingPayload,
    main.bodytype?.toLowerCase?.()
  );
  display(markup);
});

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

function calculateConfidence(percentage) {
  const confidenceMap = {
    high: {
      color: '#86efac',
      backgroundColor: '#166534',
      text: 'High Confidence'
    },
    medium: {
      color: '#fdba74',
      backgroundColor: '#9a3412',
      text: 'Medium Confidence'
    },
    low: {
      color: '#fca5a5',
      backgroundColor: '#991b1b',
      text: 'Low Confidence'
    }
  };

  if (percentage >= 0.8) {
    return confidenceMap.high;
  } else if (percentage >= 0.5) {
    return confidenceMap.medium;
  } else {
    return confidenceMap.low;
  }
}

// Official API call with retry logic
// Official API call through PHP proxy with retry logic
async function callOfficialAPI(payload, maxRetries = 3) {
  let lastError = null;

  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      const response = await fetch(
        `${PHP_PROXY_URL}?path=api/v1/recommended-price`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        }
      );

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          `API error ${response.status}: ${JSON.stringify(errorData)}`
        );
      }

      const data = await response.json();
      console.log(`[Official API] Success on attempt ${attempt}`);
      return data;
    } catch (error) {
      lastError = error;
      console.error(
        `[Official API] Attempt ${attempt}/${maxRetries} failed:`,
        error.message
      );

      if (attempt < maxRetries) {
        // Exponential backoff: 1s, 2s, 4s
        const delay = Math.pow(2, attempt - 1) * 1000;
        console.log(`[Official API] Retrying in ${delay}ms...`);
        await new Promise(r => setTimeout(r, delay));
      }
    }
  }

  throw lastError || new Error('Official API failed after all retries');
}
