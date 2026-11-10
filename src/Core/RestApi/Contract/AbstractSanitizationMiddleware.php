<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\RestApi\Contract;

use ImperaTagadaProHeadless\Core\Support\Utils;
use WP_REST_Request;
abstract class AbstractSanitizationMiddleware
{
    /**
     * @param WP_REST_Request $request
     * @return array
     * ['param_name' => 'float']
     */
    abstract protected function getRules(WP_REST_Request $request): array;

    /**
     * Core middleware execution logic invoked by the Action pipeline.
     */
    public function __invoke(WP_REST_Request $request, callable $next): mixed
    {
        $rules = $this->getRules($request);

        /**
         * set_param() is enough on its own: WP_REST_Request::set_param() writes
         * the value into every parameter bag that already holds the key — JSON
         * included — so get_json_params() sees the sanitized value as well.
         *
         * Copying the JSON bag over the body params with set_body_params() is
         * what this used to do, and that replaced the POST bag wholesale.
         */
        foreach ($rules as $paramName => $rule) {

            $rawValue = $request->get_param($paramName);

            $request->set_param($paramName, $this->sanitize($rule, $rawValue));
        }

        return $next($request);
    }


    public function sanitize(string $rule, mixed $value): mixed
    {

        return match ($rule) {
            'int'   => Utils::parseInteger($value),
            'float' => Utils::parseFloat($value),
            'bool'  => Utils::parseBool($value),
            'text'  => Utils::parseText($value),
            'key'   => Utils::parseKey($value),
            'email'   => Utils::parseEmail($value),
            default => $value
        };
    }
}
