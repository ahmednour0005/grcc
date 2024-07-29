<?php

namespace App\Http\Controllers\admin\serial;

define('MANAGEMENT_PATH', 'https://www.advancedcontrols.sa/grc-man/public');
// define('MANAGEMENT_PATH', 'http://127.0.0.1:8001');

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class SerialController extends Controller
{
    public function check_serial(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_number' => ['required', 'max:19'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();

            $response = array(
                'status' => false,
                'errors' => $errors,
                'message' => __('locale.Validation error'),
            );
            return response()->json($response, 422);
        } else {

            try {
                $response = Http::post(MANAGEMENT_PATH . '/api/v1/serial/check', [
                    "serial_number" => $request->serial_number,
                    "app_key" => env('APP_KEY', '')
                ]);

                $responseData = $response->object();

                if ($responseData->status) { // serial is valid

                    if (property_exists($responseData, 'data'))
                        $decryptedResponse = decrypt($responseData->data);

                    if ($decryptedResponse) {
                        $decryptedResponse = json_decode($decryptedResponse);

                        # Reading from files
                        // `t` catch time file
                        $oldCatchedDateFile = File::get(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/t');
                        // `ed` expiration date file
                        $expirationDateFile = File::get(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/ed');
                        // am` algorithm meta data encryption/decryption as `cipher_algo` and `cipher_algo_key` file
                        $algorithmMetaFile = File::get(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/am');

                        // Write current server time to file
                        $dateFileWriter = fopen(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/t', "w");
                        fwrite($dateFileWriter, $decryptedResponse->t);
                        fclose($dateFileWriter);

                        // Write encrypted expiration date to file
                        $expirationDateFileWriter = fopen(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/ed', "w");
                        fwrite($expirationDateFileWriter, $decryptedResponse->d);
                        fclose($expirationDateFileWriter);

                        // Write encrypted algorithm meta data encryption/decryption as `cipher_algo` and `cipher_algo_key` date to file
                        $algorithmMetaFileWriter = fopen(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/am', "w");
                        fwrite($algorithmMetaFileWriter, $decryptedResponse->m);
                        fclose($algorithmMetaFileWriter);

                        $response = Http::post(MANAGEMENT_PATH . '/api/v1/serial/mark-used', [
                            "serial_number" => $request->serial_number,
                            "app_key" => env('APP_KEY', '')
                        ]);

                        if ($response->failed()) { // reset data
                            // Write current server time to file
                            $dateFileWriter = fopen(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/t', "w");
                            fwrite($dateFileWriter, $oldCatchedDateFile);
                            fclose($dateFileWriter);

                            // Write encrypted expiration date to file
                            $expirationDateFileWriter = fopen(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/ed', "w");
                            fwrite($expirationDateFileWriter, $expirationDateFile);
                            fclose($expirationDateFileWriter);

                            // Write encrypted algorithm meta data encryption/decryption as `cipher_algo` and `cipher_algo_key` date to file
                            $algorithmMetaFileWriter = fopen(base_path() . '/vendor/laravel/framework/src/Illuminate/Encryption/am', "w");
                            fwrite($algorithmMetaFileWriter, $algorithmMetaFile);
                            fclose($algorithmMetaFileWriter);

                            $response = array(
                                'status' => false,
                                'errors' => [],
                                'message' => __('locale.ThereAreUnexpectedProblems'),
                            );

                            return response()->json($response, 502);
                        } else {

                            $response = array(
                                'status' => true,
                                'redirectTo' => route('admin.dashboard'),
                                'message' => __('locale.LicenseCheckPassed'),
                            );
                            return response()->json($response, 200);
                        }
                    } else {
                        $response = array(
                            'status' => false,
                            'errors' => [],
                            'message' => __('locale.ThereAreUnexpectedProblems'),
                        );
                        return response()->json($response, 502);
                    }
                } else { // serial isn't valid
                    $response = array(
                        'status' => false,
                        'message' => __('locale.LicenseCheckFailed'),
                    );
                    return response()->json($response, 404);
                }
            } catch (\Throwable $th) {
                $response = array(
                    'status' => false,
                    'errors' => [],
                    'message' => __('locale.ThereAreUnexpectedProblems'),
                );
                return response()->json($response, 502);
            }
        }
    }
}
