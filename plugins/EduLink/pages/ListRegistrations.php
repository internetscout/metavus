<?PHP
#
#   FILE:  ListRegistrations.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Plugin - Plugin for this page
#   $H_RegistrationListUI - ItemListUI to display the list of current registrations
#   $H_NumRegistrations - Total number of registrations
#   $H_Registrations - Array of registration objects to display on this page,
#       after subsetting for pagination

namespace Metavus;
use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\LMSRegistration;
use Metavus\Plugins\EduLink\LMSRegistrationFactory;

# ----- MAIN -----------------------------------------------------------------

User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$ItemsPerPage = 50;

$RegistrationFields = [
    "Issuer" => [
        "Heading" => "Issuer",
    ],
    "ClientId" => [
        "Heading" => "Client Id",
    ],
    "LMS" => [
        "Heading" => "Platform",
    ],
    "ContactEmail" => [
        "Heading" => "Contact Email",
        "ValueFunction" => function ($Item, $FieldId) {
            return '<a href="mailto:'.$Item->getContactEmail().'">'
                .$Item->getContactEmail().'</a>';
        },
    ],
];

$H_RegistrationListUI = new ItemListUI($RegistrationFields);
$RLTransport = new TransportControlsUI();
$H_RegistrationListUI->setTransportControls($RLTransport);
$H_RegistrationListUI->fieldsSortableByDefault(false);
$H_RegistrationListUI->setNoItemsMessage(
    "No LTI registrations"
);

if (User::getCurrentUser()->hasPriv(PRIV_SYSADMIN)) {
    $H_RegistrationListUI->addTopButton(
        "Add New Registration",
        "index.php?P=P_EduLink_EditRegistration&ID=NEW",
        "Plus.svg"
    );
}
$H_RegistrationListUI->addActionButton(
    "Edit",
    "index.php?P=P_EduLink_EditRegistration&ID=\$ID",
    "Pencil.svg"
);
$H_RegistrationListUI->addActionButton(
    "Delete",
    "index.php?P=P_EduLink_DeleteRegistration&ID=\$ID",
    "Delete.svg"
);

$H_Plugin = EduLink::getInstance();

$Factory = new LMSRegistrationFactory();
$RegistrationIds = $Factory->getItemIds();

$H_NumRegistrations = count($RegistrationIds);
$RegistrationIds = array_slice(
    $RegistrationIds,
    $RLTransport->startingIndex(),
    $RLTransport->itemsPerPage()
);

$H_Registrations = [];
foreach ($RegistrationIds as $Id) {
    $H_Registrations[$Id] = new LMSRegistration($Id);
}
