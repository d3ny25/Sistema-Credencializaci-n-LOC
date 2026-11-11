<?php

return [
    'enabled' => filter_var(env('LOCAL_DEMO_ACCESS', false), FILTER_VALIDATE_BOOL),
];
