<?php

namespace App\Exceptions;

/**
 * Thrown when a manually-typed author name doesn't exist in the system and
 * Google Books has no record of it either — surfaced to the client as a 422.
 */
class AuthorNotFoundException extends \RuntimeException
{
}
