<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSmtpSettingsRequest;
use App\Services\Mail\SmtpSettings;
use Illuminate\Http\Request;

/** Global SMTP (Super Admin only, `role:super_admin` route group). No endpoint ever returns the stored password. */
class SmtpSettingsController extends Controller
{
    public function __construct(private readonly SmtpSettings $smtp) {}

    public function show()
    {
        return $this->ok($this->smtp->reload()->snapshot());
    }

    public function update(UpdateSmtpSettingsRequest $request)
    {
        return $this->ok($this->smtp->update($request->validated(), $request->user()), __('messages.smtp.saved'));
    }

    public function testConnection()
    {
        return $this->ok($this->smtp->testConnection());
    }

    /** Sends to the signed-in Super Admin's own address only; no recipient can be supplied. */
    public function testEmail(Request $request)
    {
        return $this->ok($this->smtp->sendTestTo($request->user()));
    }
}
