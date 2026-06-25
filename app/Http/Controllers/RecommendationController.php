<?php

namespace App\Http\Controllers;

use App\Services\RecommendationService;

class RecommendationController extends Controller
{
    public function __construct(private RecommendationService $service) {}

    public function index()
    {
        $data = $this->service->forUser(auth()->id());

        return response()->json(['data' => $data]);
    }

    public function collaborative()
    {
        $data = $this->service->collaborativeForUser(auth()->id());

        return response()->json(['data' => $data]);
    }
}
