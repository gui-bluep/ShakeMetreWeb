<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectMetreResource;
use App\Models\Metre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Serves the métré portal that ShakeDesign currently renders natively on its Project
 * layout (the métré_prj_MET__ table occurrence), so the portal can keep working once the
 * metres live here instead of in the ShakeMetre FileMaker file.
 *
 * Read-only and machine-to-machine: see routes/api.php for the ability that gates it.
 */
class ProjectMetreController extends Controller
{
    /**
     * The single Sanctum ability a token needs to reach this endpoint. Read-only by
     * construction: no route grants a write counterpart.
     */
    public const ABILITY = 'metres:read';

    /**
     * Beyond this many metres the response is paginated. A project normally has a handful,
     * so the common case stays a single unpaginated list.
     */
    private const PAGINATION_THRESHOLD = 50;

    public function index(Request $request, string $zkp): AnonymousResourceCollection
    {
        $query = Metre::query()
            // FileMaker MET_Metre::zkf_PRJ, migrated as `project_id`. It holds a ShakeDesign
            // PRJ_Projects.zkp, so there is no local relation to constrain against.
            ->where('project_id', $zkp)
            ->orderBy('ind_project')
            ->orderBy('name');

        // An unknown project and a project with no metres are indistinguishable from here -
        // projects live in ShakeDesign - so both answer 200 with an empty list rather than 404.
        $total = $query->clone()->count();

        if ($total > self::PAGINATION_THRESHOLD) {
            return ProjectMetreResource::collection(
                $query->paginate(self::PAGINATION_THRESHOLD)->withQueryString()
            );
        }

        return ProjectMetreResource::collection($query->get());
    }
}
