<?PHP
$Files = [
    'src/JWTExceptionWithPayloadInterface.php',
    'src/BeforeValidException.php',
    'src/ExpiredException.php',
    'src/SignatureInvalidException.php',
    'src/JWT.php',
    'src/JWK.php',
    'src/Key.php',
];

$Dir = dirname(__FILE__)."/";
foreach ($Files as $File) {
    require_once($Dir.$File);
}
