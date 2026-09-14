<?php

/*
|--------------------------------------------------------------------------
| Authentication lines — Bahasa Indonesia
|--------------------------------------------------------------------------
|
| These three keys are Laravel's own, and the framework ships English copies of them.
| Without this file an Indonesian customer sees the whole site in Indonesian and then
| gets an English sentence the moment a password is wrong — which is exactly the moment
| they most need to understand it.
|
| DRAFT: written here to get the flow working end to end. Have the client read it —
| they know how they address their customers better than a translation does.
|
*/

return [

    /*
     * Deliberately vague about WHICH half was wrong. Firebase answers the same way for
     * an unknown address and a wrong password, so that the form cannot be used to test
     * which email addresses have accounts, and this wording must not give it back.
     */
    'failed' => 'Email atau kata sandi tidak cocok dengan data kami.',

    'password' => 'Kata sandi salah.',

    'throttle' => 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam :seconds detik.',

];
