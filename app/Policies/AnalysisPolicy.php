<?php

namespace App\Policies;

use App\Models\TextAnalysis;
use App\Models\User;

class AnalysisPolicy
{
    /**
     * Determine if the user can view the given analysis.
     * Users can only access feedback for analyses they own.
     */
    public function view(User $user, TextAnalysis $analysis): bool
    {
        return $user->id === $analysis->user_id;
    }
}
