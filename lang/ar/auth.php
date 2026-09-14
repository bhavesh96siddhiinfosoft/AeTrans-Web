<?php

/*
|--------------------------------------------------------------------------
| Authentication lines — العربية
|--------------------------------------------------------------------------
|
| These three keys are Laravel's own, and the framework ships English copies of them.
| Without this file an Arabic customer sees the whole site in Arabic and then gets an
| English sentence the moment a password is wrong — which is exactly the moment they
| most need to understand it.
|
| DRAFT, like lang/id/auth.php: written to get the flow working end to end. Have it read
| by somebody who speaks Arabic before it is shown to a customer.
|
*/

return [

    /*
     * Deliberately vague about WHICH half was wrong. Firebase answers the same way for
     * an unknown address and a wrong password, so that the form cannot be used to test
     * which email addresses have accounts, and this wording must not give it back.
     */
    'failed' => 'البريد الإلكتروني أو كلمة المرور غير مطابقين لسجلاتنا.',

    'password' => 'كلمة المرور غير صحيحة.',

    'throttle' => 'محاولات تسجيل دخول كثيرة. يرجى المحاولة مرة أخرى بعد :seconds ثانية.',

];
