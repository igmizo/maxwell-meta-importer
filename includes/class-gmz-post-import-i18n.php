<?php

class Maxwell_Post_Import_i18n
{
    public function load_plugin_textdomain()
    {
        load_plugin_textdomain(
            'gmz-post-import',
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );

    }


}
