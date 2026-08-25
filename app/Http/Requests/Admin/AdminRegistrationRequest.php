<?php

namespace App\Http\Requests\Admin;

use App\Support\AdminRegistrationCaptcha;
use App\Support\AdminRegistrationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AdminRegistrationSettings::class)->canRegister();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/', 'unique:admins,mobile', 'unique:admins,username'],
            'captcha' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => '请填写显示名称',
            'mobile.required' => '请填写手机号',
            'mobile.regex' => '请填写有效的手机号',
            'mobile.unique' => '该手机号已被注册',
            'captcha.required' => '请填写图形验证码',
            'password.required' => '请填写密码',
            'password.min' => '密码至少需要 8 位',
            'password.confirmed' => '两次输入的密码不一致',
            'password_confirmation.required' => '请再次输入密码',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $captcha = trim((string) $this->input('captcha', ''));
                if ($captcha === '') {
                    return;
                }

                if (! app(AdminRegistrationCaptcha::class)->validate($this, $captcha)) {
                    $validator->errors()->add('captcha', '图形验证码不正确，请刷新后重试');
                }
            },
        ];
    }
}
