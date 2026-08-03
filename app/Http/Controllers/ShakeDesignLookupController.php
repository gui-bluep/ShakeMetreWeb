<?php

namespace App\Http\Controllers;

use App\Services\ShakeDesign\ShakeDesignApiException;
use App\Services\ShakeDesign\ShakeDesignClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only lookups into ShakeDesign for the pickers that choose a company or one of its
 * contacts. Nothing here writes, so a readonly account may use them like any other read.
 *
 * Both return the display name alongside the zkp: the picker shows the name, the caller
 * stores the zkp.
 */
class ShakeDesignLookupController extends Controller
{
    public function companies(Request $request, ShakeDesignClient $client): JsonResponse
    {
        $validated = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $companies = $client->listCompanies($validated['q'] ?? null);

        return response()->json([
            'data' => array_values(array_filter(array_map(
                fn (array $company) => [
                    'zkp' => trim((string) ($company['zkp'] ?? '')),
                    'name' => trim((string) ($company['Name'] ?? '')) ?: 'Société sans nom',
                    // Shown as a disambiguator when two companies share a name.
                    'vat' => trim((string) ($company['VAT'] ?? '')) ?: null,
                    'city' => trim((string) ($company['AddressBill_City'] ?? '')) ?: null,
                ],
                $companies,
            ), fn (array $company) => $company['zkp'] !== '')),

            /*
             * Reported, not hidden: a list that stops at the cap looks exactly like a complete
             * one, so the picker would present a partial roster as the whole of it. The client
             * says so and invites a search instead.
             */
            'truncated' => count($companies) >= ShakeDesignClient::COMPANY_LIST_LIMIT,
            'limit' => ShakeDesignClient::COMPANY_LIST_LIMIT,
        ]);
    }

    /**
     * The contacts of one company, via JCPYCTC_JoinCompaniesContacts.
     *
     * The API_JCPYCTC layout this needs does exist today. The guard below is for the case where
     * it stops existing - renamed, or dropped from the Data API's exposed layouts - because
     * then "this company has no contacts" and "ShakeDesign can no longer answer that question"
     * would otherwise look identical to whoever is using the picker, and the first reading is
     * the one that quietly loses data.
     */
    public function contacts(string $company, ShakeDesignClient $client): JsonResponse
    {
        try {
            $contacts = $client->contactsForCompany($company);
        } catch (ShakeDesignApiException $e) {
            if ($e->isMissingLayout()) {
                return response()->json([
                    'message' => 'Les contacts sont momentanément indisponibles : le layout API_JCPYCTC '
                        .'est introuvable dans ShakeDesign (table JCPYCTC_JoinCompaniesContacts, '
                        .'champs zkf_CPY et zkf_CTC).',
                ], 503);
            }

            throw $e;
        }

        return response()->json(['data' => $contacts]);
    }
}
