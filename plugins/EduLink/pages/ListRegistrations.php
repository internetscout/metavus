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

$H_Plugin = EduLink::getInstance();
User::requirePrivilege(
    ...array_merge([PRIV_SYSADMIN], $H_Plugin->getViewMetricsPrivs())
);

$ShowAll = $_GET["ALL"] ?? false;

$ItemsPerPage = 50;
$RegistrationFields = [];

if ($ShowAll) {
    $RegistrationFields += [
        "Internal" => [
            "Heading" => "Internal",
            "ValueFunction" => function ($Item, $FieldId): string {
                return $Item->getIsInternal() ? "&check;" : "";
            }
        ]
    ];
}

$RegistrationFields += [
    "InstitutionName" => [
        "Heading" => "Institution",
    ],
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
        "ValueFunction" => function ($Item, $FieldId): string {
            return '<a href="mailto:'.$Item->getContactEmail().'">'
                .$Item->getContactEmail().'</a>';
        },
    ],
];

$H_RegistrationListUI = new ItemListUI($RegistrationFields);
$H_RegistrationListUI->fieldsSortableByDefault(false);
$H_RegistrationListUI->setNoItemsMessage(
    "No LTI registrations"
);
$H_RegistrationListUI->setItemsPerPage($ItemsPerPage);

# ----- DISPLAY --------------------------------------------------------------
$H_RegistrationListUI->addTopCheckbox(
    "Show Internal",
    $_GET["ALL"] ?? false,
    "ALL",
    "index.php?P=P_EduLink_ListRegistrations",
    "Include internal LMS registrations in list"
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

$Factory = new LMSRegistrationFactory();
$RegistrationIds = $Factory->getItemIds(
    !$ShowAll ? "IsInternal = 0" : ""
);

$H_NumRegistrations = count($RegistrationIds);
$H_RegistrationListUI->setTotalItemCount($H_NumRegistrations);

$RegistrationIds = array_slice(
    $RegistrationIds,
    $H_RegistrationListUI->getStartingIndex() ?? 0,
    $ItemsPerPage
);

$H_Registrations = [];
foreach ($RegistrationIds as $Id) {
    $H_Registrations[$Id] = new LMSRegistration($Id);
}
