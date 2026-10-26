<?php
declare(strict_types=1);

layout_start('Not found');
echo empty_state('Page not found', 'The page you are looking for does not exist.', '<a class="btn" href="' . url('dashboard') . '">Back to dashboard</a>');
layout_end();
