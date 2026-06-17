<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Publisher;

class PublisherController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $publishers = Publisher::all();
        return response()->json($publishers);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $publisher = Publisher::create([
        'name' => $request->name,
        'address' => $request->address,
        'contact_info' => $request->contact_info
        ]);
        return response()->json($publisher, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        return Publisher::findOrFail($id);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        return Publisher::findOrFail($id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $publisher = Publisher::findOrFail($id);
        $publisher->update([
            'name' => $request->name,
            'address' => $request->address,
            'contact_info' => $request->contact_info
        ]);
        return response()->json($publisher);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $publisher = Publisher::findOrFail($id);
        $publisher->delete();
        return response()->json(['message' => 'Xóa thành công']);
    }
}
