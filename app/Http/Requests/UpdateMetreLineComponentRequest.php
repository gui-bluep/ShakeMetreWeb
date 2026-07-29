<?php

namespace App\Http\Requests;

/**
 * Same editable set and same rejection rules as creating one - a component has no field that
 * may be set once and never changed - so the store request carries the whole contract and this
 * only exists to name the operation at the route.
 */
class UpdateMetreLineComponentRequest extends StoreMetreLineComponentRequest {}
