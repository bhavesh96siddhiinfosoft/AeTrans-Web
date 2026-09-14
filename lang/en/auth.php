<?php

/*
|--------------------------------------------------------------------------
| Authentication lines — English
|--------------------------------------------------------------------------
|
| Laravel ships these three in English already, so this file changes nothing on its own.
| It is here so that all three languages are complete in their own folder: someone adding
| a fourth language copies a folder rather than discovering that English lives somewhere
| else and that two of the three lines have been reworded since.
|
*/

return [

    /*
     * Deliberately vague about WHICH half was wrong. Firebase answers the same way for
     * an unknown address and a wrong password, so that the form cannot be used to test
     * which email addresses have accounts, and this wording must not give it back.
     */
    'failed' => 'These credentials do not match our records.',

    'password' => 'The provided password is incorrect.',

    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

];
