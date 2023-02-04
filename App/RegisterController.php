<?php

namespace App\Http\Controllers\App;

use Auth;
use Validator;
use App\Models\User;
use App\Models\Setting;
use App\Models\RoleUser;
use App\Models\VerifyUser;
use Illuminate\Http\Request;
use App\Http\Helpers\Common; 
use App\Http\Controllers\Controller; 
use App\Http\Controllers\Users\EmailController;

class RegisterController extends Controller
{
    protected $helper;
    protected $email;
    protected $user;
    public $vs;

    public function __construct()
    {
        $this->helper = new Common();
        $this->email  = new EmailController();
        $this->user   = new User();
        $this->vs = "v2";
    }
 

    public function registration(Request $request)
    {
        if ($_POST)
        {
            // dd($request->all());
            if($request->has_captcha == 'Enabled') {
                $rules = array(
                    'first_name'            => 'required',
                    'last_name'             => 'required',
                    'email'                 => 'required|email|unique:users,email',
                    'phone'                 => 'required',
                    'password'              => 'required|confirmed',
                    'password_confirmation' => 'required',
                    'g-recaptcha-response'  => 'required|captcha',
                );

                $validator = Validator::make($request->all(), $rules, [
                    'g-recaptcha-response.required' => 'Captcha is required.',
                    'g-recaptcha-response.captcha'  => 'Please enter correct captcha.',
                ]);

            } else {
                $rules = array(
                    'first_name'            => 'required',
                    'last_name'             => 'required',
                    'email'                 => 'required|email|unique:users,email',
                    'phone'                 => 'required',
                    'password'              => 'required|confirmed',
                    'password_confirmation' => 'required',
                );

                $validator = Validator::make($request->all(), $rules);
            }

            $fieldNames = array(
                'first_name'            => 'First Name',
                'last_name'             => 'Last Name',
                'email'                 => 'Email',
                'phone'                 => 'Phone Number',
                'password'              => 'Password',
                'password_confirmation' => 'Confirm Password',
            );
            
            $validator->setAttributeNames($fieldNames);

            if ($validator->fails())
            {
                return response()->json($validator, 422) ;
            }
            else
            {
                try
                {
                    $default_currency = Setting::where('name', 'default_currency')->first(['value']);
                    
                    \DB::beginTransaction();

                    // Create user
                    $user = $this->user->createNewUser($request, 'user');

                    // Assign user type and role to new user
                    RoleUser::insert(['user_id' => $user->id, 'role_id' => $user->role_id, 'user_type' => 'User']);

                    // Create user detail
                    $this->user->createUserDetail($user->id);

                    // Create user's default wallet
                    $this->user->createUserDefaultWallet($user->id, $default_currency->value);

                    // Create user's crypto wallet/wallets address
                    // $generateUserCryptoWalletAddress = $this->user->generateUserCryptoWalletAddress($user);
                    // // dd($generateUserCryptoWalletAddress);
                    // if ($generateUserCryptoWalletAddress['status'] == 401)
                    // {
                    //     \DB::rollBack();
                    //     $this->helper->one_time_message('error', $generateUserCryptoWalletAddress['message']);
                    //     return redirect('/login');
                    // }

                    $userEmail          = $user->email;
                    $userFormattedPhone = $user->formattedPhone;

                    // Process Registered User Transfers
                    // $this->user->processUnregisteredUserTransfers($userEmail, $userFormattedPhone, $user, $default_currency->value);

                    // Process Registered User Request Payments
                    // $this->user->processUnregisteredUserRequestPayments($userEmail, $userFormattedPhone, $user, $default_currency->value);


                    // Email verification
                    if (!$user->user_detail->email_verification)
                    {
                        if (checkVerificationMailStatus() == "Enabled")
                        {
                            if (checkAppMailEnvironment())
                            {
                                $emailVerificationArr = $this->user->processUserEmailVerification($user);
                                
                                try
                                {
                                    if(is_array($emailVerificationArr))
                                    {
                                        $this->email->sendEmail($emailVerificationArr['email'], $emailVerificationArr['subject'], $emailVerificationArr['message']);

                                        $msg = ['success' => 'We sent you an email verification link. Check your email and click on the link to proceed.'];
                                    } else {
                                        $msg = ['warning' => 'You have exceeded the limit for email verification. Please contact support.'];
                                    }
                                    
                                    \DB::commit();
                                    
                                    return response()->json($msg, isset($msg['success']) ? 200:429);
                                }
                                catch (\Exception $e)
                                {
                                    \DB::rollBack(); 
                                    return response()->json($e->getMessage(), 500);

                                }
                            }
                        }
                    }

                    \DB::commit();

                    $msg = ['success' => 'Registration successful!'];
                    
                    return response()->json($msg);
                }
                catch (\Exception $e)
                {
                    \DB::rollBack();
                    \Log::debug($e->getMessage());
                    $msg = ['error' => 'Unable to complete registration. Kindly contact support.' . $e->getMessage()];
                    return response($msg, 500);
                }
            }
        }
    }

    public function verifyUser($token)
    {
        $verifyUser = VerifyUser::where('token', $token)->first();
        if (isset($verifyUser))
        {
            if (!$verifyUser->user->user_detail->email_verification)
            {
                $verifyUser->user->user_detail->email_verification = 1;
                $verifyUser->user->user_detail->save();
                $status = __("Your email is now verified. You can login to proceed.");
            }
            else
            {
                $status = __("Your email is already verified. You can login to proceed.");
            }
        }
        else
        {
            return redirect('/login')->with('warning', __("Sorry your email could not be verified."));
        }
        return redirect('/login')->with('status', $status);
    }

    public function checkUserRegistrationEmail(Request $request)
    {
        $email = User::where(['email' => $request->email])->exists();
        if ($email)
        {
            $data['status'] = true;
            $data['fail']   = __('The email has already been taken!');
        }
        else
        {
            $data['status']  = false;
            $data['success'] = "Email Available!";
        }
        return json_encode($data);
    }

    public function registerDuplicatePhoneNumberCheck(Request $request)
    {

        $request->validate([
            'phone' => 'required'
        ]);
        // dd($request->all());
        // dd(preg_replace("/[\s-]+/", "", $request->phone));

        if (isset($request->carrierCode))
        {
            $user = User::where(['phone' => preg_replace("/[\s-]+/", "", $request->phone), 'carrierCode' => $request->carrierCode])->first(['phone', 'carrierCode']);
        }
        else
        {
            $user = User::where(['phone' => preg_replace("/[\s-]+/", "", $request->phone)])->first(['phone', 'carrierCode']);
        }

        if (!empty($user->phone) && !empty($user->carrierCode))
        {
            $data['status'] = true;
            $data['fail']   = "The phone number has already been taken!";
        }
        else
        {
            $data['status']  = false;
            $data['success'] = "The phone number is Available!";
        }
        return json_encode($data);
    }
}
