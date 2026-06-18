<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Models\Author;
use App\Http\Controllers\Controller;

class AuthorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    
    public function index()
    {
        $authors = Author::where('is_active', true)->paginate(10);
        return response()->json($authors);
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
        $validated = $request->validate([
        'name' => ['sometimes','required','string','max:255'],
        'bio' => ['sometimes','nullable','string'],
        'birth_date' => ['sometimes','nullable','date'],
        'nationality' => ['sometimes','nullable','string','max:255'],
        'is_active' => ['sometimes','boolean']
        ]);

    $author = Author::create($validated);

    return response()->json($author,201);
    }
        
    

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        return Author::with('books')->findOrFail($id);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        return Author::findOrFail($id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $author = Author::findOrFail($id);
        $validated = $request->validate([
        'name' => ['sometimes','required','string','max:255'],
        'bio' => ['sometimes','nullable','string'],
        'birth_date' => ['sometimes','nullable','date'],
        'nationality' => ['sometimes','nullable','string','max:255'],
        'is_active' => ['sometimes','boolean']
        ]);

        $author->update($validated);
        return response()->json($author);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $author = Author::findOrFail($id);

        $author->update([
            'is_active' => false
        ]);

        return response()->json([
            'message' => 'Ẩn tác giả thành công'
        ]);
    }
    public function restore(int $id)
    {
        $author = Author::findOrFail($id);

        $author->update([
            'is_active' => true
        ]);

        return response()->json([
            'message' => 'Khôi phục tác giả thành công'
        ]);
    }
    public function search(Request $request)
    {
        return Author::where('is_active', true)->where(
            'name',
            'like',
            '%' . $request->keyword . '%'
        )->get();
    }
}
