<?php get_header(); ?>

<div class="main">
    <div class="mob-menu">
        <div class="mob-menu-wrpr">
            <div class="padding-mob-menu">
                <div class="w-layout-hflex flex-h space-beetween">
                    <div class="nav-l-col">
                        <a href="<?php echo home_url(); ?>" aria-current="page" class="logo-link w-inline-block w--current"><img src="<?php echo get_template_directory_uri(); ?>/assets/images/logo.svg" loading="lazy" alt="" class="logo invert"></a>
                    </div>
                    <div class="w-layout-hflex flex-h gap-12 align-center">
                        <a href="tel:+<?php str_replace(array('(', ')', '+', ' ', '-'), '', get_field('fp_phone', 'options'));?>" class="btn-small-white w-inline-block"><img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-phone.svg" loading="lazy" alt="" class="icon-24 invert">
                            <div class="txt-20">1-222-333-44-55</div>
                        </a><svg data-w-id="7dac115d-bbfc-ba54-a965-a9bbfc3496cf" xmlns="http://www.w3.org/2000/svg" width="100%" viewbox="0 0 48 48" fill="none" class="menu-btn-open">
                            <rect width="48" height="48" rx="24" fill="white"></rect>
                            <path d="M34.5712 16.1864L31.8135 13.4287L24 21.2422L16.1864 13.4287L13.4287 16.1864L21.2422 24L13.4287 31.8135L16.1864 34.5712L24 26.7577L31.8135 34.5712L34.5712 31.8135L26.7577 24L34.5712 16.1864Z" fill="black"></path>
                        </svg>
                    </div>
                </div>
                <div class="w-layout-hflex nav-wrpr-mob">
                    <a href="<?php echo home_url(); ?>#how-it-works" class="menu-link mob w-inline-block">
                        <div class="txt-36">How it Works</div>
                    </a>
                    <a href="<?php echo home_url(); ?>#why-us" class="menu-link mob w-inline-block">
                        <div class="txt-36">Why US?</div>
                    </a>
                    <a href="<?php echo home_url(); ?>#reviews" class="menu-link mob w-inline-block">
                        <div class="txt-36">Reviews</div>
                    </a>
                    <a href="<?php echo home_url(); ?>#faq" class="menu-link mob w-inline-block">
                        <div class="txt-36">FAQ</div>
                    </a>
                </div>
                <a href="<?php echo home_url(); ?>" class="btn w-inline-block">
                    <div class="w-layout-hflex flex-h gap-12 align-center">
                        <div class="txt-24">Get Shipping Estimate</div>
                        <div class="tag-small blue">
                            <div class="txt-16">FREE</div>
                        </div>
                    </div><img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-r.svg" loading="lazy" alt="" class="icon-24">
                </a>
            </div>
        </div>
    </div>
    <div class="header">
        <div class="nav-l-col">
            <a href="<?php echo home_url(); ?>" aria-current="page" class="logo-link w-inline-block w--current"><img src="<?php echo get_template_directory_uri(); ?>/assets/images/logo.svg" loading="lazy" alt="" class="logo"></a>
        </div>
        <div class="w-layout-hflex nav-wrpr">
            <a href="<?php echo home_url(); ?>#how-it-works" class="menu-link w-inline-block">
                <div class="txt-20">How it Works</div>
            </a>
            <a href="<?php echo home_url(); ?>#why-us" class="menu-link w-inline-block">
                <div class="txt-20">Why Us?</div>
            </a>
            <a href="<?php echo home_url(); ?>#reviews" class="menu-link w-inline-block">
                <div class="txt-20">Reviews</div>
            </a>
            <a href="<?php echo home_url(); ?>#faq" class="menu-link w-inline-block">
                <div class="txt-20">FAQ</div>
            </a>
        </div>
        <div class="nav-r-col">
            <a href="#" class="btn-small mob-hide w-inline-block"><img src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-phone.svg" loading="lazy" alt="" class="icon-24">
                <div class="txt-20"><?php the_field('fp_phone', 'options')?></div>
            </a>
            <div class="menu-open-btn"><svg data-w-id="1b328051-cdc6-44ee-c463-530bdc36807f" xmlns="http://www.w3.org/2000/svg" width="100%" viewbox="0 0 48 48" fill="none" class="menu-open-btn">
                    <rect width="48" height="48" rx="24" fill="black" class="rect"></rect>
                    <path d="M14 19H34M14 24H34M14 29H34" stroke="white" stroke-width="3" stroke-linecap="square" stroke-linejoin="round"></path>
                </svg></div>
        </div>
    </div>

    <div id="how-it-works" class="section">
        <div class="padding">
            <div class="container">
                <div class="main-grid">
                    <div id="w-node-_1a45f6f8-cd06-0a93-44a9-35b0ae4748de-de33ec15" class="w-layout-vflex flex-v gap-40">
                        <div class="w-layout-vflex flex-v gap-36 mob-nogap">
                            <h2 class="h1-txt"><span class="txt-bg-orange"><?php the_title();?></span></h2>
                        </div>

                        <div class="content">
                            <?php the_content();?>
                        </div>

                    </div>
                </div>
            </div>
        </div><img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png" loading="lazy" sizes="(max-width: 479px) 100vw, (max-width: 991px) 62vw, (max-width: 1919px) 53vw, 49vw" srcset="<?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-500.png 500w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg-p-800.png 800w, <?php echo get_template_directory_uri(); ?>/assets/images/arrows-bg.png 1046w" alt="" class="arrows-bg">
    </div>
</div>

<?php get_footer(); ?>
