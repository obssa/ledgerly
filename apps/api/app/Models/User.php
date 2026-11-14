<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\Models\Membership;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;
    use Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_confirmed_at', 'mfa_last_step'];

    protected $appends = ['mfa_enabled', 'email_verified'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted', // never stored readable: a database leak must not leak sign-in codes
            'mfa_confirmed_at' => 'datetime',
            'mfa_last_step' => 'integer',
        ];
    }

    /** True once the person proved the address is theirs, by the link sent to it. */
    protected function emailVerified(): Attribute
    {
        return Attribute::get(fn () => $this->email_verified_at !== null);
    }

    /** True once the authenticator app is set up and proven: from then on sign-in asks for a code. */
    protected function mfaEnabled(): Attribute
    {
        return Attribute::get(fn () => $this->mfa_confirmed_at !== null);
    }

    /** A new API token for one signed-in session; it expires (config/sanctum.php). */
    public function issueToken(): string
    {
        return $this->createToken('web', ['*'], now()->addMinutes((int) config('sanctum.expiration', 720)))->plainTextToken;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
