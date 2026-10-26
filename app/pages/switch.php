<?php
declare(strict_types=1);

if (is_post()) {
    csrf_check();
    $id = (int)post('company_id');
    $_SESSION['company_id'] = ($id === 0 || Auth::canCompany($id)) ? $id : 0;
}
back();
