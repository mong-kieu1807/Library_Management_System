<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $primaryKey = 'user_id';

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function libraryCard()
    {
        return $this->hasOne(LibraryCard::class, 'user_id');
    }

    public function borrowTransactions()
    {
        return $this->hasMany(BorrowTransaction::class, 'user_id');
    }

    public function processedBorrows()
    {
        return $this->hasMany(BorrowTransaction::class, 'librarian_id', 'user_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }

    public function fines()
    {
        return $this->hasMany(Fine::class, 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'actor_id', 'user_id');
    }

    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class, 'user_id');
    }

    public function bookEditHistories()
    {
        return $this->hasMany(BookEditHistory::class, 'edited_by', 'user_id');
    }

    public function copyRetirements()
    {
        return $this->hasMany(CopyRetirement::class, 'retired_by', 'user_id');
    }

    public function holidays()
    {
        return $this->hasMany(Holiday::class, 'created_by', 'user_id');
    }

    public function reportExports()
    {
        return $this->hasMany(ReportExport::class, 'exported_by', 'user_id');
    }

    public function backupLogs()
    {
        return $this->hasMany(BackupLog::class, 'created_by', 'user_id');
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AIRecommendation::class, 'user_id');
    }

    public function aiChatSessions()
    {
        return $this->hasMany(AIChatSession::class, 'user_id');
    }
    public $incrementing = true;
    protected $fillable = [
        'full_name',
        'email',
        'password',
        'phone',
        'address',
        'role_id',
        'status',
        'avatar_url',
        'librarian_level',
        'google2fa_secret',
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
