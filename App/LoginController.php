<?php

namespace App\Http\Controllers\App;
 
use App\Models\Setting; 
use App\Http\Controllers\Controller; 
use App\Http\Requests\AppLoginRequest; 
use App\Modules\Authentication\Facades\AppLogin; 

class LoginController extends Controller
{
   

    public function login(AppLoginRequest $request)
    {
        $loginMode = Setting::where('name', 'login_via')->first(['value'])->value;
        $data = $request->validated();
        $response = AppLogin::login($data, $data['login_mode']);
        
        return response()->json($response, $response['statusCode']);


    }

   
}
