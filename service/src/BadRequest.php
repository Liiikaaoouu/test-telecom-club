<?php
declare(strict_types=1);

namespace DailyPass;

/**
 * A client-side error at the HTTP boundary (bad JSON, wrong types, impossible
 * date). Answered with 400 — never with a 500 and never with a PHP warning in
 * the response body.
 */
final class BadRequest extends \RuntimeException
{
}
