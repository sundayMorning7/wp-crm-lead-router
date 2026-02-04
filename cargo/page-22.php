<?php

/*
if (!isset($_POST['md_name']) && !isset($_POST['md_email'])) {
    wp_redirect(get_permalink(20));
}*/

get_header();


?>

    <div class="main">
        <div class="section-black steps">
            <div class="padding steps-form">
                <div class="container">
                    <div class="w-layout-vflex steps-wrpr">
                        <a href="<?php echo home_url(); ?>" class="logo-link w-inline-block"><img
                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/logo_1.svg"
                                    loading="lazy" alt="" class="logo"></a>
                        <div id="w-node-_643a6a58-5dec-d809-7ee0-f8497f5028d5-2d708b12" class="flex-v gap-12">
                            <div id="w-node-a0fa474a-b78a-001d-c764-41eb1fc03aa2-2d708b12" class="thnx-page-wrpr green">
                                <div class="w-layout-vflex flex-v gap-24">
                                    <?php the_field('th_block1'); ?>
                                </div>
                            </div>
                            <div id="w-node-_8db218d3-43f7-7b37-6910-09e2f3a4f7a0-2d708b12" class="thnx-page-wrpr">
                                <div class="w-layout-vflex flex-v gap-24 stretch">
                                    <p class="txt-32 mob-40"><strong>What Happens Next?</strong></p>
                                    <div class="flex-v">
                                        <?php the_field('th_block2'); ?>
                                    </div>
                                    <div class="thnx-txt-btn-wrpr">
                                        <p class="txt-20 mob-txt-center"><strong>Need help?</strong><br>Speak with a
                                            specialist now:</p>

                                        <a href="tel:+<?php str_replace(array('(', ')', '+', ' ', '-'), '', get_field('fp_phone', 'options')); ?>"
                                           class="btn-small w-inline-block"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/tabler-icon-phone.svg"
                                                    loading="lazy" alt="" class="icon-24">
                                            <div class="txt-20"><?php the_field('fp_phone', 'options') ?></div>
                                        </a>
                                    </div>
                                    <div class="thnx-review-wrpr">
                                        <div class="flex-h gap-12"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/avas01.png"
                                                    loading="lazy" alt="" class="ava-64">
                                            <div class="flex-v gap-12">
                                                <p class="txt-20">I saved $200 just by comparing quotes—super easy
                                                    process!</p>
                                                <p class="txt-16 op-60">— Alex M., Florida</p>
                                            </div>
                                        </div>
                                        <div class="flex-h gap-12"><img
                                                    src="<?php echo get_template_directory_uri(); ?>/assets/images/avas21.png"
                                                    loading="lazy" alt="" class="ava-64">
                                            <div class="flex-v gap-12">
                                                <p class="txt-20">The carriers were professional, and I got the best
                                                    rate hassle-free!</p>
                                                <p class="txt-16 op-60">– Sarah T., Texas</p>
                                            </div>
                                        </div>
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


