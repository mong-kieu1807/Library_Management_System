<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function libraryCard()
    {
        return $this->hasOne(LibraryCard::class);
    }

    public function borrowTransactions()
    {
        return $this->hasMany(BorrowTransaction::class);
    }

    public function processedBorrows()
    {
        return $this->hasMany(BorrowTransaction::class, 'librarian_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function fines()
    {
        return $this->hasMany(Fine::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class);
    }

    public function bookEditHistories()
    {
        return $this->hasMany(BookEditHistory::class, 'edited_by');
    }

    public function copyRetirements()
    {
        return $this->hasMany(CopyRetirement::class, 'retired_by');
    }

    public function holidays()
    {
        return $this->hasMany(Holiday::class, 'created_by');
    }

    public function reportExports()
    {
        return $this->hasMany(ReportExport::class, 'exported_by');
    }

    public function backupLogs()
    {
        return $this->hasMany(BackupLog::class, 'created_by');
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AIRecommendation::class);
    }

    public function aiChatSessions()
    {
        return $this->hasMany(AIChatSession::class);
    }
    protected $primaryKey = 'user_id';
    public $incrementing = true;
    protected $fillable = [
    'email',
    'password',
    'full_name',
    'phone',
    'address',
    'role_id',
    'status',
    'avatar_url',
    'created_at',
    'updated_at'
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
