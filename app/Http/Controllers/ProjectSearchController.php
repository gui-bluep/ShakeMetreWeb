<?php

namespace App\Http\Controllers;

use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the dashboard's search box. A blank query never reaches ShakeDesign - the
 * dashboard shows no project until the user actually searches, so there is nothing to
 * look up until then.
 */
class ProjectSearchController extends Controller
{
    public function __invoke(Request $request, ShakeDesignClient $client): JsonResponse
    {
        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        $term = trim((string) ($validated['q'] ?? ''));

        if ($term === '') {
            return response()->json(['data' => []]);
        }

        /*
         * No active/inactive flag: PRJ_Projects.isActive_b is not exposed on API_PRJ
         * (confirmed against the live layout, which returns zkp/Name/Number/Status/zkf_CPY/
         * zkf_CTC), and the concept was dropped rather than inferred from a field that is not
         * there. `Status` is real and exposed, so that is what the dashboard shows instead.
         */
        return response()->json([
            'data' => array_map(
                fn (array $project) => [
                    'id' => $project['zkp'] ?? null,
                    'name' => $project['Name'] ?? null,
                    'number' => $project['Number'] ?? null,
                    'status' => $project['Status'] ?? null,
                ],
                $client->searchProjects($term),
            ),
        ]);
    }
}
