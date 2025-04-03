<?PHP
#
#   FILE:  JWKS.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#

namespace Metavus;

use Metavus\Plugins\EduLink;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# output a JSON Web Key Set (JWKS) containing our public key
#
# (some LMSes do not allow us to provide a public key to them directly,
#  instead asking for a URL they can ping to get a JWKS)
#
# @see https://auth0.com/docs/secure/tokens/json-web-tokens/json-web-key-sets
# @see https://datatracker.ietf.org/doc/html/rfc7517

ApplicationFramework::getInstance()->beginAjaxResponse();
print EduLink::getInstance()->getPublicJWKS();
