<?php
/*
if ((!isset($_POST['year']) && !isset($_POST['brand'])) && (!isset($_COOKIE['year']) && !isset($_COOKIE['brand']))) {
    wp_redirect(home_url());
    exit();
}*/


if (isset($_POST['md_year']) && isset($_POST['md_brand'])) {

    $year = mb_convert_encoding($_POST['md_year'], 'UTF-8', 'UTF-8');
    $year = htmlentities($year, ENT_QUOTES, 'UTF-8');

    $brand = mb_convert_encoding($_POST['md_brand'], 'UTF-8', 'UTF-8');
    $brand = htmlentities($brand, ENT_QUOTES, 'UTF-8');


    $model = mb_convert_encoding($_POST['md_model'], 'UTF-8', 'UTF-8');
    $model = htmlentities($model, ENT_QUOTES, 'UTF-8');

    $condition = mb_convert_encoding($_POST['md_condition'], 'UTF-8', 'UTF-8');
    $condition = htmlentities($condition, ENT_QUOTES, 'UTF-8');


    global $wpdb;
    $bodytype = $wpdb->get_row(
        "SELECT bodytype FROM `w4pMd_cars` WHERE model='" . $model . "'", ARRAY_N, 0
    );


    $bodytype = mb_convert_encoding($bodytype[0], 'UTF-8', 'UTF-8');
    $bodytype = htmlentities($bodytype, ENT_QUOTES, 'UTF-8');

    $condition = mb_convert_encoding($_POST['condition'], 'UTF-8', 'UTF-8');
    $condition = htmlentities($condition, ENT_QUOTES, 'UTF-8');


    setcookie("year", $_POST['md_year'], time() + 400, '/');
    setcookie("brand", $_POST['md_brand'], time() + 400, '/');
    setcookie("model", $_POST['md_model'], time() + 400, '/');
    setcookie("bodytype", $bodytype, time() + 400, '/');
    setcookie("condition", $_POST['md_condition'], time() + 400, '/');


    wp_redirect(get_permalink(20));
    exit();
}


if (!isset($_COOKIE["md_oc"]) || !isset($_COOKIE["md_dc"]) || !isset($_COOKIE["model"]) || !isset($_COOKIE["brand"]) || !isset($_COOKIE["year"])) {
    wp_redirect(get_permalink(13));
    exit();
}


get_header(); ?>

    <div class="main">
        <div class="section-black steps">
            <div class="padding steps-form">
                <div class="container">
                    <div class="w-layout-vflex steps-wrpr">
                        <a href="<?php echo home_url(); ?>" class="logo-link w-inline-block"><img
                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/logo_1.svg"
                                    loading="lazy" alt=""
                                    class="logo invert"></a>
                        <div id="w-node-eefb943e-63f2-b861-abed-3e5c675da4da-4a9eafc8"
                             class="w-layout-hflex step-2-content-wrpr">
                            <div id="w-node-a0fa474a-b78a-001d-c764-41eb1fc03aa2-4a9eafc8" class="form-step-2">
                                <div class="w-layout-vflex flex-v gap-24">
                                    <div class="w-layout-hflex flex-h gap-24 align-center">
                                        <a href="<?php the_permalink(13); ?>"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-l.svg"
                                                    loading="lazy" alt="" class="icon-24 invert"></a>
                                        <p class="txt-32"><strong>Contact Information</strong></p>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-state _90"></div>
                                    </div>
                                </div>
                                <div class="form-block w-form">
                                    <form id="md_step3_form" method="post" class="form"
                                          action="<?php the_permalink(272); ?>">
                                        <div class="w-layout-vflex input-group"><label for="name-4"
                                                                                       class="txt-20"><strong>Your
                                                    Name</strong></label>
                                            <div class="input-wrpr"><input class="text-field w-input" maxlength="256"
                                                                           minlength="2"
                                                                           autocomplete="new-password"
                                                                           name="md_name" data-name="Name"
                                                                           placeholder="Full Name" type="text" id="Name"
                                                                           required><img
                                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-user.svg"
                                                        loading="lazy" alt=""
                                                        class="icon-24 input"></div>
                                        </div>
                                        <div class="w-layout-vflex input-group"><label for="name-4"
                                                                                       class="txt-20"><strong>Email</strong></label>
                                            <div class="input-wrpr"><input class="text-field w-input" maxlength="256"
                                                                           name="md_email" data-name="Email"
                                                                           autocomplete="new-password"
                                                                           placeholder="me@mail.com" type="email"
                                                                           id="Email"
                                                                           required><img
                                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-mail.svg"
                                                        loading="lazy" alt=""
                                                        class="icon-24 input"></div>
                                        </div>
                                        <div class="w-layout-vflex input-group md_own_mask"><label for="name-5"
                                                                                                   class="txt-20"><strong>Phone
                                                    number</strong></label>
                                            <div class="input-wrpr">
                                                <div class="phone-field-wrpr"><input
                                                            class="text-field w-input"
                                                            autocomplete="new-password"
                                                            maxlength="256" name="md_phone"
                                                            required placeholder="+1 (000) 000-0000"
                                                            type="text" id="md_test_phone2">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="w-layout-vflex input-group"><label for="name-2"
                                                                                       class="txt-20"><strong>Estimated Ship Date</strong></label>
                                            <div class="input-wrpr">

                                                <input class="date-field w-input" autocomplete="new-password"
                                                       maxlength="256" name="md_date"
                                                       placeholder="Select Date"
                                                       value=""
                                                       data-toggle="datepicker" type="text"
                                                       id="Date"
                                                       required><img
                                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-calendar-due.svg"
                                                        loading="lazy" alt=""
                                                        class="icon-24 input"></div>
                                        </div>
                                        <button id="md_step3_form_btn" class="btn w-inline-block">
                                            <div class="txt-24"><strong>Send me the Free Quote</strong></div>
                                            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                                                 loading="lazy" alt="" class="icon-24">
                                            <div class="loader"></div>
                                        </button>
                                    </form>
                                    <div class="w-form-done">
                                        <div>Thank you! Your submission has been received!</div>
                                    </div>
                                    <div class="w-form-fail">
                                        <div>Oops! Something went wrong while submitting the form.</div>
                                    </div>
                                </div>
                                <div class="w-layout-hflex flex-h gap-12 align-center">
                                    <div class="w-layout-hflex flex-h"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas15.png"
                                                loading="lazy" alt="" class="ava-64 form"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas10.png"
                                                loading="lazy" alt="" class="ava-64 form"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas24.png"
                                                loading="lazy" alt=""
                                                class="ava-64"></div>
                                    <div class="txt-16 sizing-grow"><strong>Every 5 minutes, </strong>someone trusts
                                        ShipWise for their car shipping
                                    </div>
                                </div>
                            </div>
                            <div id="w-node-_15a869a8-bab7-5500-29f6-00de7a6a68ac-4a9eafc8" class="step-3-r-col-wrpr">
                                <div class="w-layout-vflex flex-v gap-36 stretch">
                                    <p class="txt-32"><strong>Estimation Details</strong></p>
                                    <div class="w-layout-hflex flex-h gap-12 align-center">
                                        <div class="txt-20"><?php echo $_COOKIE['place_from']; ?></div>
                                        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                                             loading="lazy" alt="" class="icon-16 invert">
                                        <div class="txt-20"><?php echo $_COOKIE['place_to']; ?></div>
                                    </div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <div class="txt-20"><strong>Auto Shipping</strong></div>
                                        <div class="list-wrpr">
                                            <div class="list-item">
                                                <div class="width _4em">
                                                    <div class="txt-20">Mark:</div>
                                                </div>
                                                <div class="txt-20"><?php echo $_COOKIE['brand']; ?></div>
                                            </div>
                                            <div class="list-item">
                                                <div class="width _4em">
                                                    <div class="txt-20">Model:</div>
                                                </div>
                                                <div class="txt-20"><?php echo $_COOKIE['model']; ?></div>
                                            </div>
                                            <div class="list-item">
                                                <div class="width _4em">
                                                    <div class="txt-20">Year:</div>
                                                </div>
                                                <div class="txt-20"><?php echo $_COOKIE['year']; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <div class="txt-20"><strong>Estimated Ship Date</strong></div>
                                        <div class="list-wrpr">
                                            <div class="list-item">
                                                <div class="width _4em">
                                                    <div class="txt-20">Date:</div>
                                                </div>
                                                <div class="txt-20" id="js-md-est-date">Select Date</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <div class="txt-20"><strong>Shipping Details</strong></div>
                                        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/price-blured.jpg"
                                             loading="lazy" alt="" class="image">
                                        <div class="list-wrpr">
                                            <div class="list-item">
                                                <div class="txt-20">Finish your request to reveal</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="orange-bg"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-discount-2.svg"
                                            loading="lazy" alt="" class="icon-32">
                                    <p class="txt-16">You’re only one step away from unlocking your discount gift</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


<?php get_footer(); ?>