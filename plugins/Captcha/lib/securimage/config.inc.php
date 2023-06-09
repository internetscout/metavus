<?php

return array(
    'no_session'       => true,
    'use_database'     => true,
    'database_driver'  => Securimage::SI_DRIVER_SQLITE3,
    'database_file' => dirname(__FILE__)."/../../../../local/data/caches/Captcha/securimage.sq3",
);
