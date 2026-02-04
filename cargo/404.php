<?php get_header(); ?>

    <div class="main">
        <div class="section-black steps">
            <div class="w-layout-vflex flex-v gap-36 align-center">
                <a href="<?php echo home_url(); ?>" class="logo-link w-inline-block"><img src="<?php echo get_template_directory_uri(); ?>/assets/images/logo.svg" loading="lazy" alt="" class="logo invert"></a>
                <h1 class="h1-txt">Page not Found</h1>
                <h1 class="txt-36">404 error</h1>
                <a href="<?php echo home_url(); ?>" class="btn back w-inline-block"><img src="<?php echo get_template_directory_uri(); ?>/assets/images/arrow-l.svg" loading="lazy" alt="" class="icon-24">
                    <div class="w-layout-hflex flex-h gap-12 align-center">
                        <div class="txt-24">Back to Home Page</div>
                    </div>
                </a>
            </div>
        </div>
    </div>

<?php get_footer();


