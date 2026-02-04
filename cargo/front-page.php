<?php get_header();


if (isset($_GET['maks'])) {




/*
    $args = array(
        'posts_per_page' => -1,
        'post_status' => 'private',
        'post_type' => 'lead',
        'orderby' => 'date',
        'order' => 'ASC',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => 'crm_status',
                'value' => '2'
            ),
            array(
                'key' => 'crm_attempts',
                'value' => '2',
                'compare' => '<'
            ),
        ),
    );

    $q = new WP_Query($args);


    if ($q->have_posts()) :
        while ($q->have_posts()) : $q->the_post();

            $post_id = $q->post->ID;
            echo get_the_title($post_id) . '<br/>';
        endwhile;
    endif;

    wp_reset_query();*/

     echo md_check_day_limits();

}

?>

    <div class="main">
        <div class="mob-menu">
            <div class="mob-menu-wrpr">
                <div class="padding-mob-menu">
                    <div class="w-layout-hflex flex-h space-beetween">
                        <div class="nav-l-col">
                            <a href="<?php echo home_url(); ?>" aria-current="page"
                               class="logo-link w-inline-block w--current"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/logo_1.svg"
                                        loading="lazy" alt="" class="logo invert"></a>
                        </div>
                        <div class="w-layout-hflex flex-h gap-12 align-center">
                            <a href="tel:+<?php echo str_replace(array('(', ')', '+', ' ', '-'), '', get_field('fp_phone', 'options')); ?>"
                               class="btn-small-white w-inline-block"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-phone.svg"
                                        loading="lazy" alt="" class="icon-24 invert">
                                <div class="txt-20"><?php the_field('fp_phone', 'options'); ?></div>
                            </a>
                            <svg data-w-id="7dac115d-bbfc-ba54-a965-a9bbfc3496cf" xmlns="http://www.w3.org/2000/svg"
                                 width="100%" viewbox="0 0 48 48" fill="none" class="menu-btn-open">
                                <rect width="48" height="48" rx="24" fill="white"></rect>
                                <path
                                        d="M34.5712 16.1864L31.8135 13.4287L24 21.2422L16.1864 13.4287L13.4287 16.1864L21.2422 24L13.4287 31.8135L16.1864 34.5712L24 26.7577L31.8135 34.5712L34.5712 31.8135L26.7577 24L34.5712 16.1864Z"
                                        fill="black"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="w-layout-hflex nav-wrpr-mob">
                        <a href="#how-it-works" class="menu-link mob w-inline-block">
                            <div class="txt-36">How it Works</div>
                        </a>
                        <a href="#why-us" class="menu-link mob w-inline-block">
                            <div class="txt-36">Why US?</div>
                        </a>
                        <a href="#reviews" class="menu-link mob w-inline-block">
                            <div class="txt-36">Reviews</div>
                        </a>
                        <a href="#faq" class="menu-link mob w-inline-block">
                            <div class="txt-36">FAQ</div>
                        </a>
                    </div>
                    <a href="<?php echo home_url(); ?>" class="btn w-inline-block">
                        <div class="w-layout-hflex flex-h gap-12 align-center">
                            <div class="txt-24">Get Shipping Estimate</div>
                            <div class="tag-small blue">
                                <div class="txt-16">FREE</div>
                            </div>
                        </div>
                        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                             loading="lazy" alt="" class="icon-24">
                    </a>
                </div>
            </div>
        </div>
        <div class="header">
            <div class="nav-l-col">
                <a href="<?php echo home_url(); ?>" aria-current="page" class="logo-link w-inline-block w--current"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/logo-black.svg"
                            loading="lazy" alt=""
                            class="logo"></a>
            </div>
            <div class="w-layout-hflex nav-wrpr">
                <a href="#how-it-works" class="menu-link w-inline-block">
                    <div class="txt-20">How it Works</div>
                </a>
                <a href="#why-us" class="menu-link w-inline-block">
                    <div class="txt-20">Why Us?</div>
                </a>
                <a href="#reviews" class="menu-link w-inline-block">
                    <div class="txt-20">Reviews</div>
                </a>
                <a href="#faq" class="menu-link w-inline-block">
                    <div class="txt-20">FAQ</div>
                </a>
            </div>
            <div class="nav-r-col">
                <a href="tel:+<?php echo str_replace(array('(', ')', '+', ' ', '-'), '', get_field('fp_phone', 'options')); ?>"
                   class="btn-small mob w-inline-block"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-phone.svg"
                            loading="lazy"
                            alt="" class="icon-24">
                    <div class="txt-20 mob-hide"><?php the_field('fp_phone', 'options') ?></div>
                </a>
                <div class="menu-open-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="100%" viewbox="0 0 48 48"
                         fill="none" class="menu-open-btn">
                        <rect width="48" height="48" rx="24" fill="black" class="rect"></rect>
                        <path d="M14 19H34M14 24H34M14 29H34" stroke="white" stroke-width="3" stroke-linecap="square"
                              stroke-linejoin="round"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="js-mob-btn">
            <a href="#top" class="btn fixed w-inline-block">
                <div class="w-layout-hflex flex-h gap-12 align-center">
                    <div class="txt-24"><strong>Get Shipping Estimate</strong></div>
                    <div class="tag-small green">
                        <div class="txt-16">FREE</div>
                    </div>
                </div>
                <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg" loading="lazy" alt=""
                     class="icon-24">
            </a>
        </div>
        <div id="top" data-w-id="53f023ee-d918-fc4d-d23b-4a2b8f0b82f7" class="section-black s1">

            <?php $top_text = get_field('fp_top_line', 'options');

            if ($top_text) : ?>

                <div class="proposual-line">
                    <?php echo $top_text ?>
                </div>

            <?php endif; ?>

            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-_53f023ee-d918-fc4d-d23b-4a2b8f0b8303-de33ec15"
                             class="w-layout-vflex flex-v gap-40">
                            <div class="w-layout-hflex flex-h gap-12 align-center"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/stars.svg"
                                        loading="lazy"
                                        alt="" class="stars-img">
                                <?php the_field('fp_fs_rank', 'options') ?>
                            </div>
                            <div class="w-layout-vflex flex-v gap-36">
                                <?php the_field('fp_fs_h1', 'options') ?>
                            </div>
                        </div>
                        <div id="w-node-_53f023ee-d918-fc4d-d23b-4a2b8f0b8316-de33ec15" class="form-wrpr">
                            <div class="progress-bar">
                                <div class="progress-state _30"></div>
                            </div>
                            <p class="txt-32 in-form"><?php the_field('fp_fs_form_title', 'options') ?></p>
                            <div class="form-block w-form">
                                <!--
                            <form id="md_form_step1" style="display: none" class="form" method="post" action="<?php the_permalink(13); ?>">
                                <div class="w-layout-vflex input-group">
                                    <label class="txt-20">Transport car <strong>FROM</strong></label>
                                    <div class="input-wrpr">
                                        <input class="text-field w-input" id="place_from" name="place_from"
                                            placeholder="ZIP, City or Country" type="text" required>
                                        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-map-pin-filled.svg"
                                            loading="lazy" alt="" class="icon-24 input">
                                    </div>
                                </div>
                                <div class="w-layout-vflex input-group">
                                    <label class="txt-20">Transport car <strong>TO</strong></label>
                                    <div class="input-wrpr">
                                        <input class="text-field w-input" id="place_to" name="place_to"
                                            placeholder="ZIP, City or Country" type="text" required>
                                        <img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-map-pin-filled.svg"
                                            loading="lazy" alt="" class="icon-24 input">
                                    </div>
                                </div>
                                <button type="submit" class="btn w-inline-block">
                                    <div class="w-layout-hflex flex-h gap-12 align-center">
                                        <?php the_field('fp_fs_form_call_to_action', 'options') ?>
                                    </div><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                                        loading="lazy" alt="" class="icon-24">
                                </button>
                                <input id="md_oc" type="hidden" name="md_oc" value="">
                                <input id="md_os" type="hidden" name="md_os" value="">
                                <input id="md_oz" type="hidden" name="md_oz" value="">
                                <input id="md_dc" type="hidden" name="md_dc" value="">
                                <input id="md_ds" type="hidden" name="md_ds" value="">
                                <input id="md_dz" type="hidden" name="md_dz" value="">
                            </form>
-->

                                <form id="md_form_step_one" class="form" method="post"
                                      action="<?php the_permalink(13); ?>">
                                    <div class="w-layout-vflex input-group js-md-group">
                                        <label class="txt-20">Transport car <strong>FROM</strong></label>
                                        <div class="md_flex">
                                            <div class="input-wrpr md_dropdown js-md-search-city"
                                                 data-url="md_get_city">
                                                <input class="text-field w-input md_invalid" id="from_city"
                                                       name="from_city" placeholder="City" type="text"
                                                       autocomplete="new-password">
                                                <img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-map-pin-filled.svg"
                                                     loading="lazy" alt="" class="icon-24 input">
                                                <div class="md_dropdown_result"></div>
                                            </div>
                                            <div class="input-wrpr">

                                                <select class="text-field w-input md_invalid" id="from_state"
                                                        name="from_state" autocomplete="new-password"
                                                >
                                                    <option value="">State</option>
                                                    <option value="AL">AL</option>
                                                    <option value="AK">AK</option>
                                                    <option value="AZ">AZ</option>
                                                    <option value="AR">AR</option>
                                                    <option value="CA">CA</option>
                                                    <option value="CO">CO</option>
                                                    <option value="CT">CT</option>
                                                    <option value="DC">DC</option>
                                                    <option value="DE">DE</option>
                                                    <option value="FL">FL</option>
                                                    <option value="GA">GA</option>
                                                    <option value="HI">HI</option>
                                                    <option value="ID">ID</option>
                                                    <option value="IL">IL</option>
                                                    <option value="IN">IN</option>
                                                    <option value="IA">IA</option>
                                                    <option value="KS">KS</option>
                                                    <option value="KY">KY</option>
                                                    <option value="LA">LA</option>
                                                    <option value="ME">ME</option>
                                                    <option value="MD">MD</option>
                                                    <option value="MA">MA</option>
                                                    <option value="MI">MI</option>
                                                    <option value="MN">MN</option>
                                                    <option value="MS">MS</option>
                                                    <option value="MO">MO</option>
                                                    <option value="MT">MT</option>
                                                    <option value="NE">NE</option>
                                                    <option value="NV">NV</option>
                                                    <option value="NH">NH</option>
                                                    <option value="NJ">NJ</option>
                                                    <option value="NM">NM</option>
                                                    <option value="NY">NY</option>
                                                    <option value="NC">NC</option>
                                                    <option value="ND">ND</option>
                                                    <option value="OH">OH</option>
                                                    <option value="OK">OK</option>
                                                    <option value="OR">OR</option>
                                                    <option value="PA">PA</option>
                                                    <option value="RI">RI</option>
                                                    <option value="SC">SC</option>
                                                    <option value="SD">SD</option>
                                                    <option value="TN">TN</option>
                                                    <option value="TX">TX</option>
                                                    <option value="UT">UT</option>
                                                    <option value="VT">VT</option>
                                                    <option value="VA">VA</option>
                                                    <option value="WA">WA</option>
                                                    <option value="WV">WV</option>
                                                    <option value="WI">WI</option>
                                                    <option value="WY">WY</option>
                                                </select>
                                            </div>
                                            <div class="input-wrpr md_dropdown js-md-search-city" data-url="md_get_zip">
                                                <input class="text-field w-input md_invalid" id="from_zip"
                                                       name="from_zip" autocomplete="new-password"
                                                       placeholder="ZIP" type="text">
                                                <img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-map-pin-filled.svg"
                                                     loading="lazy" alt="" class="icon-24 input">
                                                <div class="md_dropdown_result"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="w-layout-vflex input-group js-md-group">
                                        <label class="txt-20">Transport car <strong>TO</strong></label>
                                        <div class="md_flex">
                                            <div class="input-wrpr  md_dropdown js-md-search-city"
                                                 data-url="md_get_city">
                                                <input class="text-field w-input md_invalid" id="to_city" name="to_city"
                                                       autocomplete="new-password"
                                                       placeholder="City" type="text">
                                                <img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-map-pin-filled.svg"
                                                     loading="lazy" alt="" class="icon-24 input">
                                                <div class="md_dropdown_result"></div>
                                            </div>
                                            <div class="input-wrpr">
                                                <select class="text-field w-input md_invalid" id="to_state"
                                                        name="to_state" autocomplete="new-password">
                                                    <option value="">State</option>
                                                    <option value="AL">AL</option>
                                                    <option value="AK">AK</option>
                                                    <option value="AZ">AZ</option>
                                                    <option value="AR">AR</option>
                                                    <option value="CA">CA</option>
                                                    <option value="CO">CO</option>
                                                    <option value="CT">CT</option>
                                                    <option value="DC">DC</option>
                                                    <option value="DE">DE</option>
                                                    <option value="FL">FL</option>
                                                    <option value="GA">GA</option>
                                                    <option value="HI">HI</option>
                                                    <option value="ID">ID</option>
                                                    <option value="IL">IL</option>
                                                    <option value="IN">IN</option>
                                                    <option value="IA">IA</option>
                                                    <option value="KS">KS</option>
                                                    <option value="KY">KY</option>
                                                    <option value="LA">LA</option>
                                                    <option value="ME">ME</option>
                                                    <option value="MD">MD</option>
                                                    <option value="MA">MA</option>
                                                    <option value="MI">MI</option>
                                                    <option value="MN">MN</option>
                                                    <option value="MS">MS</option>
                                                    <option value="MO">MO</option>
                                                    <option value="MT">MT</option>
                                                    <option value="NE">NE</option>
                                                    <option value="NV">NV</option>
                                                    <option value="NH">NH</option>
                                                    <option value="NJ">NJ</option>
                                                    <option value="NM">NM</option>
                                                    <option value="NY">NY</option>
                                                    <option value="NC">NC</option>
                                                    <option value="ND">ND</option>
                                                    <option value="OH">OH</option>
                                                    <option value="OK">OK</option>
                                                    <option value="OR">OR</option>
                                                    <option value="PA">PA</option>
                                                    <option value="RI">RI</option>
                                                    <option value="SC">SC</option>
                                                    <option value="SD">SD</option>
                                                    <option value="TN">TN</option>
                                                    <option value="TX">TX</option>
                                                    <option value="UT">UT</option>
                                                    <option value="VT">VT</option>
                                                    <option value="VA">VA</option>
                                                    <option value="WA">WA</option>
                                                    <option value="WV">WV</option>
                                                    <option value="WI">WI</option>
                                                    <option value="WY">WY</option>
                                                </select>
                                            </div>
                                            <div class="input-wrpr md_dropdown js-md-search-city" data-url="md_get_zip">
                                                <input class="text-field w-input md_invalid" id="to_zip" name="to_zip"
                                                       placeholder="ZIP" type="text" autocomplete="new-password">
                                                <img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-map-pin-filled.svg"
                                                     loading="lazy" alt="" class="icon-24 input">
                                                <div class="md_dropdown_result"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn w-inline-block">
                                        <div class="w-layout-hflex flex-h gap-12 align-center">
                                            <?php the_field('fp_fs_form_call_to_action', 'options') ?>
                                        </div>
                                        <img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                                                loading="lazy" alt="" class="icon-24">
                                    </button>
                                </form>

                            </div>
                            <div class="w-layout-hflex flex-h gap-12 align-center">
                                <div class="w-layout-hflex flex-h"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas01.png"
                                            loading="lazy" alt="" class="ava-64 form"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas02.png"
                                            loading="lazy" alt="" class="ava-64 form"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas03.png"
                                            loading="lazy" alt="" class="ava-64"></div>
                                <div class="txt-16 sizing-grow"><strong>145 people</strong> shipped using SHIPWISE
                                    Transport
                                    <strong>last week</strong>
                                </div>
                            </div>
                            <div class="w-layout-hflex flex-h gap-12 align-center"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/certificate.svg"
                                        loading="lazy" alt="" class="icon-24">
                                <div class="txt-14">Your details are only shared with verified carriers to ensure the
                                    best
                                    deals.
                                </div>
                            </div>
                        </div>
                        <div id="w-node-_53f023ee-d918-fc4d-d23b-4a2b8f0b8349-de33ec15"
                             class="w-layout-hflex flex-h gap-12 mob-wrap-center">
                            <div class="tag"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-discount-check.svg"
                                        loading="lazy" alt="" class="icon-24">
                                <div class="txt-20 line-heigh-1">Vetted Providers</div>
                            </div>
                            <div class="tag"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-bolt.svg"
                                        loading="lazy" alt="" class="icon-24">
                                <div class="txt-20 line-heigh-1">Fast Process</div>
                            </div>
                            <div class="tag"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-eye-dollar.svg"
                                        loading="lazy" alt="" class="icon-24">
                                <div class="txt-20 line-heigh-1">Transparent Pricing</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/s1-bg.jpg" loading="lazy"
                 sizes="100vw"
                 srcset="<?php echo get_template_directory_uri(); ?>/assets/images/s1-bg-p-500.jpg 500w, <?php echo get_template_directory_uri(); ?>/assets/images/s1-bg-p-800.jpg 800w, <?php echo get_template_directory_uri(); ?>/assets/images/s1-bg-p-1080.jpg 1080w, <?php echo get_template_directory_uri(); ?>/assets/images/s1-bg-p-1600.jpg 1600w, <?php echo get_template_directory_uri(); ?>/assets/images/s1-bg.jpg 1920w"
                 alt="" class="bg-s1">
        </div>
        <div id="how-it-works" class="section">
            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-_1a45f6f8-cd06-0a93-44a9-35b0ae4748de-de33ec15"
                             class="w-layout-vflex flex-v gap-40">
                            <div class="w-layout-vflex flex-v gap-36 mob-nogap">
                                <?php the_field('fp_ss_title', 'options') ?>
                            </div>
                            <div class="w-layout-vflex flex-v gap-12">
                                <div class="card-wrpr">
                                    <div class="w-layout-vflex s2-card-txt-wrpr">
                                        <?php the_field('fp_ss_st1', 'options') ?>
                                    </div>
                                    <img src="<?php echo get_template_directory_uri(); ?>/assets/images/s2-img01.jpg"
                                         loading="lazy" alt="" class="s2-img">
                                </div>
                                <div class="card-wrpr _2nd"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/s2-img02.jpg"
                                            loading="lazy" alt="" class="s2-img">
                                    <div class="w-layout-vflex s2-card-txt-wrpr">
                                        <?php the_field('fp_ss_st2', 'options') ?>
                                    </div>
                                </div>
                                <div class="card-wrpr">
                                    <div class="w-layout-vflex s2-card-txt-wrpr">
                                        <?php the_field('fp_ss_st3', 'options') ?>
                                    </div>
                                    <img src="<?php echo get_template_directory_uri(); ?>/assets/images/s2-img03.jpg"
                                         loading="lazy" alt="" class="s2-img">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png" loading="lazy"
                 sizes="(max-width: 479px) 100vw, (max-width: 991px) 62vw, (max-width: 1919px) 53vw, 49vw"
                 srcset="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-500.png 500w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-800.png 800w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png 1046w"
                 alt="" class="arrows-bg">
        </div>
        <div id="why-us" class="section-black">
            <div class="avas-wrpr">
                <div class="w-layout-hflex avas-line-1"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas22.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas10.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas07.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas08.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas04.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas01.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas02.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas09.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas03.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas12.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas06.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas05.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas11.png" loading="lazy"
                            alt=""
                            class="ava-128"></div>
                <div class="w-layout-hflex avas-line-2"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas27.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas15.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas25.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas23.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas20.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas16.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas14.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas13.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas26.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas24.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas17.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas21.png"
                            loading="lazy" alt="" class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas18.png" loading="lazy"
                            alt=""
                            class="ava-128"><img
                            src="<?php echo get_template_directory_uri(); ?>/assets/images/avas28.png"
                            loading="lazy" alt="" class="ava-128"></div>
            </div>
            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-ccefa7da-3c4b-17aa-80cb-1056dfc2c171-de33ec15"
                             class="w-layout-vflex flex-v gap-40">
                            <?php the_field('fp_ts_title', 'options') ?>
                            <div class="w-layout-vflex flex-v gap-12">
                                <div class="card-wrpr-s3">
                                    <?php the_field('fp_ts_st1', 'options') ?>
                                </div>
                                <div class="card-wrpr-s3">
                                    <?php the_field('fp_ts_st2', 'options') ?>
                                </div>
                                <div class="card-wrpr-s3">
                                    <?php the_field('fp_ts_st3', 'options') ?>
                                </div>
                                <div class="card-wrpr-s3">
                                    <?php the_field('fp_ts_st4', 'options') ?>
                                </div>
                            </div>
                            <?php the_field('fp_ts_bt', 'options') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="reviews" class="section-white">
            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-_4842e6bc-d223-eb71-ec43-e2d83cfc0012-de33ec15"
                             class="w-layout-vflex flex-v gap-36">
                            <?php the_field('fp_fos_title', 'options') ?>
                            <div data-delay="4000" data-animation="slide" class="slider w-slider" data-autoplay="false"
                                 data-easing="ease" data-hide-arrows="false" data-disable-swipe="false"
                                 data-autoplay-limit="0" data-nav-spacing="3" data-duration="500" data-infinite="true">
                                <div class="mask w-slider-mask">


                                    <?php while (have_rows('fp_fos_reviews', 'options')):
                                        the_row(); ?>

                                        <div class="slide w-slide">
                                            <div class="review-card-wrpr">
                                                <div class="w-layout-vflex flex-v gap-4 align-center">
                                                    <div class="ava-review-wrpr">
                                                        <?php

                                                        $image = get_sub_field('fp_fos_reviews_img');
                                                        $size = 'full'; // (thumbnail, medium, large, full or custom size)
                                                        if ($image) {
                                                            echo wp_get_attachment_image($image, $size, '', array(
                                                                'class' => 'ava-96',
                                                                'loading' => 'lazy',
                                                            ));
                                                            echo wp_get_attachment_image($image, $size, '', array(
                                                                'class' => 'ava-blur-review',
                                                                'loading' => 'lazy',
                                                            ));
                                                        }
                                                        ?>
                                                    </div>

                                                    <p class="txt-20"><?php the_sub_field('fp_fos_reviews_name') ?></p>
                                                    <p class="txt-16 op-60"><?php the_sub_field('fp_fos_reviews_location') ?>
                                                    </p>
                                                </div>
                                                <div class="w-layout-vflex flex-v gap-12 aling-center"><img
                                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/stars-5.svg"
                                                            loading="lazy" alt="" class="stars-img">
                                                    <p class="txt-16 txt-center"><?php the_sub_field('fp_fos_reviews_text') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                    <?php endwhile; ?>
                                </div>
                                <div class="left-arrow w-slider-arrow-left"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-l.svg"
                                            loading="lazy" alt="" class="icon-24"></div>
                                <div class="right-arrow w-slider-arrow-right"><img
                                            src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg"
                                            loading="lazy" alt="" class="icon-24"></div>
                                <div class="slide-nav w-slider-nav w-round w-num"></div>
                            </div>
                            <div class="w-layout-hflex flex-h gap-12 mob-wrap-center">
                                <?php the_field('fp_fos_bt', 'options') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png" loading="lazy"
                 sizes="(max-width: 479px) 100vw, (max-width: 991px) 62vw, (max-width: 1919px) 53vw, 49vw"
                 srcset="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-500.png 500w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-800.png 800w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png 1046w"
                 alt="" class="arrows-bg">
        </div>
        <div id="faq" class="section">
            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-_3b229ac4-e0cb-9601-de61-12ccbb609585-de33ec15"
                             class="w-layout-vflex flex-v gap-36 stretch">
                            <div class="w-layout-hflex header-txt-wrpr">
                                <?php the_field('fp_fis_title', 'options') ?>
                            </div>
                            <div class="w-layout-vflex flex-v gap-12">

                                <?php $i = 1;
                                while (have_rows('fp_fis_faq', 'options')):
                                    the_row(); ?>
                                    <div class="accardion">
                                        <div class="flex-v">
                                            <div class="w-layout-hflex num-question-wrpr">
                                                <div class="faq-num-wrpr">
                                                    <div class="txt-32">0<?php echo $i; ?></div>
                                                </div>
                                                <div class="txt-32 semibold"><?php the_sub_field('question') ?></div>
                                            </div>
                                            <div class="accordion-target">
                                                <div class="answer-wrpr">
                                                    <p class="txt-24"><?php the_sub_field('answer') ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="100%" viewbox="0 0 48 48"
                                             fill="none" class="icon-btn">
                                            <rect width="48" height="48" rx="24" fill="white"></rect>
                                            <path d="M25.95 11H22.05V22.05H11V25.95H22.05V37H25.95V25.95H37V22.05H25.95V11Z"
                                                  fill="black"></path>
                                        </svg>
                                    </div>

                                    <?php $i++;
                                endwhile; ?>

                                <div class="txt-wrpr">
                                    <?php the_field('fp_fis_bt', 'options') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="section-black mod-overflow-hidden">
            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-_9f67c1b4-b94c-7776-793d-53285dc6a379-de33ec15"
                             class="w-layout-vflex flex-v gap-36">
                            <div class="w-layout-vflex flex-v gap-36">
                                <?php the_field('fp_six_title', 'options') ?>
                            </div>
                            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/car-img.png"
                                 loading="lazy"
                                 sizes="(max-width: 479px) 100vw, (max-width: 991px) 87vw, (max-width: 1919px) 57vw, 53vw"
                                 srcset="<?php echo get_template_directory_uri(); ?>/assets/images/car-img-p-500.png 500w, <?php echo get_template_directory_uri(); ?>/assets/images/car-img-p-800.png 800w, <?php echo get_template_directory_uri(); ?>/assets/images/car-img-p-1080.png 1080w, <?php echo get_template_directory_uri(); ?>/assets/images/car-img.png 1248w"
                                 alt="" class="car-img"><img
                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png"
                                    loading="lazy"
                                    sizes="(max-width: 479px) 100vw, (max-width: 991px) 64vw, (max-width: 1919px) 41vw, 38vw"
                                    srcset="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-500.png 500w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-800.png 800w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png 1046w"
                                    alt="" class="s6-arrows-bg">
                            <div class="w-layout-vflex flex-h gap-12 mob-v">
                                <div class="s6-card">
                                    <div class="icon-wrpr-72"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-certificate.svg"
                                                loading="lazy" alt="" class="icon-32"></div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('fp_six_step1', 'options') ?>
                                    </div>
                                </div>
                                <div class="s6-card">
                                    <div class="icon-wrpr-72"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/search.svg"
                                                loading="lazy" alt="" class="icon-32"></div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('fp_six_step2', 'options') ?>
                                    </div>
                                </div>
                                <div class="s6-card">
                                    <div class="icon-wrpr-72"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/sparckles.svg"
                                                loading="lazy" alt="" class="icon-32"></div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('fp_six_step3', 'options') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="section">
            <div class="padding">
                <div class="container">
                    <div class="main-grid">
                        <div id="w-node-_41095d24-0e88-664b-aafc-de93525db411-de33ec15"
                             class="w-layout-vflex flex-v gap-36">
                            <div class="w-layout-hflex header-txt-wrpr">
                                <?php the_field('fp_sev_title', 'options') ?>
                            </div>
                            <div class="s7-grid">
                                <div id="w-node-_1912dcf9-9781-024e-5915-5d75a9ae1ae2-de33ec15" class="s7-card">
                                    <div class="w-layout-hflex flex-h"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas10.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas17.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas14.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas07.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas01.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas03.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas08.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas02.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas18.png"
                                                loading="lazy" alt="" class="ava-72 mrgn-l-1em"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/avas04.png"
                                                loading="lazy" alt="" class="ava-72"></div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('fp_sev_block1', 'options') ?>
                                    </div>
                                </div>
                                <div id="w-node-b0e7c8aa-aaef-386d-21cf-8c0d6a5d6704-de33ec15" class="s7-card">
                                    <div class="icon-wrpr-72"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-receipt-2.svg"
                                                loading="lazy" alt="" class="icon-32"></div>
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('fp_sev_block2', 'options') ?>
                                    </div>
                                </div>
                                <div id="w-node-ddf17815-462a-97a3-8b5c-ee000a35955a-de33ec15" class="s7-black-card">
                                    <div class="w-layout-vflex flex-v gap-12">
                                        <?php the_field('fp_sev_block3', 'options') ?>
                                    </div>
                                    <a href="tel:+<?php str_replace(array('(', ')', '+', ' ', '-'), '', get_field('fp_phone', 'options')); ?>"
                                       class="btn-small-white w-inline-block"><img
                                                src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-phone.svg"
                                                loading="lazy" alt="" class="icon-24 invert">
                                        <div class="txt-20"><?php the_field('fp_phone', 'options') ?></div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png" loading="lazy"
                 sizes="(max-width: 479px) 100vw, (max-width: 991px) 62vw, (max-width: 1919px) 53vw, 49vw"
                 srcset="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-500.png 500w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-800.png 800w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png 1046w"
                 alt="" class="arrows-bg">
        </div>
        <div data-w-id="870ee25d-d313-b69c-9cfc-8216da28cc02" class="footer">
            <div class="width _60em">
                <div class="w-layout-hflex flex-h space-beetween tab-align-left mob-v">
                    <div class="w-layout-hflex footer-logo-txt">
                        <a href="<?php echo home_url(); ?>" aria-current="page"
                           class="logo-link w-inline-block w--current"><img
                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/logo.svg"
                                    loading="lazy"
                                    alt="" class="logo"></a>
                        <p class="txt-14">2024 SHIPWISE © All rights reserved</p>
                    </div>
                    <div class="w-layout-hflex flex-h gap-40 align-center mob-v">
                        <div class="width _12em">
                            <p class="txt-14">Don&#x27;t hesitateto contact us</p>
                        </div>
                        <div class="w-layout-hflex flex-h gap-40">
                            <a href="<?php the_field('fs_linkedin', 'options') ?>" class="txt-icon-link w-inline-block"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/linked-in-icon.svg"
                                        loading="lazy" alt="" class="icon-48">
                                <p class="txt-20">LinkedIn</p>
                            </a>
                            <a href="mailto:<?php the_field('fs_email', 'options') ?>"
                               class="txt-icon-link w-inline-block"><img
                                        src="<?php echo get_template_directory_uri(); ?>/assets/images/mail-icon.svg"
                                        loading="lazy" alt="" class="icon-48">
                                <p class="txt-20"><?php the_field('fs_email', 'options') ?></p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <a href="<?php the_permalink(85); ?>" class="footer-link w-inline-block">
                <p class="txt-14">Privacy policy</p>
            </a>
        </div>
    </div>


<?php get_footer(); ?>