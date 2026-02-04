<?php
if (isset($_POST['from_city']) && isset($_POST['to_city'])) {
/*
    $place_from = mb_convert_encoding($_POST['place_from'], 'UTF-8', 'UTF-8');
    $place_from = htmlentities($place_from, ENT_QUOTES, 'UTF-8');

    $place_to = mb_convert_encoding($_POST['place_to'], 'UTF-8', 'UTF-8');
    $place_to = htmlentities($place_to, ENT_QUOTES, 'UTF-8');*/


    setcookie("place_from", $_POST['from_city'] . ',' . $_POST['from_state'] . ',' . $_POST['from_zip'] , time() + 400, '/');
    setcookie("place_to", $_POST['to_city'] . ',' . $_POST['to_state'] . ',' . $_POST['to_zip'], time() + 400, '/');


    setcookie("md_oc", $_POST['from_city'], time() + 400, '/');
    setcookie("md_os", $_POST['from_state'], time() + 400, '/');
    setcookie("md_oz", $_POST['from_zip'], time() + 400, '/');
    setcookie("md_dc", $_POST['to_city'], time() + 400, '/');
    setcookie("md_ds", $_POST['to_state'], time() + 400, '/');
    setcookie("md_dz", $_POST['to_zip'], time() + 400, '/');

    wp_redirect(get_permalink(13));
    exit();
}



if (!isset($_COOKIE["md_oc"]) || !isset($_COOKIE["md_dc"])) {
    wp_redirect(home_url());
    exit();
}



global $wpdb;
$models = $wpdb->get_results(
    "SELECT DISTINCT brand FROM `w4pMd_cars`", ARRAY_N
);


get_header(); ?>


    <div class="main">
        <div class="section-black steps">
            <div class="padding steps-form">
                <div class="container">
                    <div class="w-layout-vflex steps-wrpr">
                        <a href="<?php echo home_url(); ?>" class="logo-link w-inline-block"><img
                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/logo_1.svg"
                                    loading="lazy" alt="" class="logo invert"></a>
                        <div id="w-node-eefb943e-63f2-b861-abed-3e5c675da4da-16abcf4d"
                             class="w-layout-hflex step-2-content-wrpr">
                            <div id="w-node-a0fa474a-b78a-001d-c764-41eb1fc03aa2-16abcf4d" class="form-step-2">
                                <div class="w-layout-vflex flex-v gap-24">
                                    <div class="w-layout-hflex flex-h gap-24 align-center">
                                        <a href="<?php echo home_url(); ?>"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-l.svg"
                                                loading="lazy" alt="" class="icon-24 invert"></a>
                                        <p class="txt-32"><strong>Vehicle Information</strong></p>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-state _60"></div>
                                    </div>
                                </div>
                                <div class="form-block w-form">
                                    <form method="post" class="form" action="<?php the_permalink(20); ?>">
                                        <div class="w-layout-vflex input-group"><label for="name-4"
                                                                                       class="txt-20"><strong>Year</strong></label>
                                            <div class="input-wrpr">


                                                <select name="md_year" class="js-choice-year text-field" required autocomplete="new-password">
                                                    <option value="">Type to search years...</option>
                                                    <?php

                                                    $years = range(date('Y'), 1900);
                                                    arsort($years, SORT_NUMERIC);
                                                    foreach ($years as $year) {
                                                        echo '<option value="' . $year . '">' . $year . '</option>';
                                                    }
                                                    ?>

                                                </select>

                                                <img loading="lazy"
                                                     src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-search.svg"
                                                     alt="" class="icon-24 input">
                                            </div>
                                        </div>
                                        <div class="w-layout-vflex input-group"><label for="name-4"
                                                                                       class="txt-20"><strong>Make</strong></label>
                                            <div class="input-wrpr">

                                                <select class="js-choice-brand text-field" name="md_brand" required autocomplete="new-password">
                                                    <option value="">Type to search marks...</option>
                                                    <?php

                                                    foreach ($models as $model) {
                                                        echo '<option value="' . $model[0] . '">' . $model[0] . '</option>';
                                                    }
                                                    ?>

                                                </select>
                                                <img loading="lazy"
                                                     src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-search.svg"
                                                     alt=""
                                                     class="icon-24 input">
                                            </div>
                                        </div>
                                        <div class="w-layout-vflex input-group"><label for="name-5"
                                                                                       class="txt-20"><strong>Model</strong></label>

                                            <div class="input-wrpr">

                                                <select class="js-choice-model text-field" name="md_model" required autocomplete="new-password">
                                                    <option value="">Type to search models...</option>
                                                </select>
                                                <img
                                                        loading="lazy"
                                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-search.svg"
                                                        alt=""
                                                        class="icon-24 input"></div>
                                        </div>
                                        <div class="w-layout-vflex input-group"><label for="name-5"
                                                                                       class="txt-20"><strong>Condition</strong></label>
                                            <div class="w-layout-vflex flex-v gap-12">

                                                <label for="name-5" class="txt-16 op-60">A running vehicle can pull itself on and off a carrier under its power.</label>

                                                <div class="w-layout-hflex flex-h gap-32">
                                                    <div class="radio">
                                                        <input id="r1" type="radio" name="md_condition" value="Running" checked>
                                                        <label for="r1" class="txt-20">Running</label>
                                                    </div>

                                                    <div class="radio">
                                                        <input id="r2" type="radio" name="md_condition" value="NonRunning">
                                                        <label for="r2" class="txt-20">Nonrunning</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn w-inline-block">
                                            <div class="w-layout-hflex flex-h gap-12 align-center">
                                                <div class="txt-24"><strong>Go to Final Step</strong></div>
                                            </div>
                                            <img loading="lazy"
                                                 src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                                                 alt="" class="icon-24"><input
                                                    type="submit" data-wait="" class="js-submit submit-button w-button" value="">
                                        </button>
                                    </form>
                                </div>
                                <div class="w-layout-hflex flex-h gap-12 align-center">
                                    <div class="w-layout-hflex flex-h"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas04.png"
                                                loading="lazy"
                                                alt="" class="ava-64 form"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas19.png"
                                                loading="lazy" alt="" class="ava-64 form"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas16.png"
                                                loading="lazy" alt="" class="ava-64"></div>
                                    <div class="txt-16 sizing-grow"><strong>5-star reviews from 96% </strong>of shippers—your
                                        car is in good hands
                                    </div>
                                </div>
                            </div>
                            <div id="w-node-_15a869a8-bab7-5500-29f6-00de7a6a68ac-16abcf4d" class="step-2-r-col-wrpr">
                                <div class="list-wrpr">
                                    <div class="w-layout-hflex list-item-wrpr first">
                                        <div class="icon-wrpr-72 blue"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-message-circle-check.svg"
                                                    loading="lazy"
                                                    alt="" class="icon-32"></div>
                                        <div class="w-layout-vflex flex-v gap-12">
                                            <?php the_field('shipping_block1') ?>
                                        </div>
                                    </div>
                                    <div class="w-layout-hflex list-item-wrpr">
                                        <div class="icon-wrpr-72 blue"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/icon-car-white.svg"
                                                    loading="lazy" alt="" class="icon-32"></div>
                                        <div class="w-layout-vflex flex-v gap-12">
                                            <?php the_field('shipping_block2') ?>
                                        </div>
                                    </div>
                                    <div class="w-layout-hflex list-item-wrpr">
                                        <div class="icon-wrpr-72 blue"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/icon-flash.svg"
                                                    loading="lazy"
                                                    alt="" class="icon-32"></div>
                                        <div class="w-layout-vflex flex-v gap-12">
                                            <?php the_field('shipping_block3') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="w-layout-vflex flex-v">
                                    <div class="w-layout-hflex">
                                        <p class="txt-36 txt-orange">&quot;</p><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas14.png"
                                                loading="lazy" alt=""
                                                class="ava-64 no-border">
                                    </div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('shipping_blockquote') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


<?php get_footer(); ?>