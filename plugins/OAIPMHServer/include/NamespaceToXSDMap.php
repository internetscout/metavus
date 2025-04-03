<?PHP
$NamespaceToXSDMap = [
    "http://ns.nsdl.org/access_type_v1.00/" => "access_rights_type/access_type_v1.00.xsd",
    "http://ns.nsdl.org/audience_type_v1.00/" => "audience_type/audience_type_v1.00.xsd",
    "http://ns.nsdl.org/db_insert/collRecs" => "db_insert/collRecs_v0.0.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v0.1" => "db_insert/collRecs_v0.1.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.00" => "db_insert/collRecs_v1.00.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.01" => "db_insert/collRecs_v1.01.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.02" => "db_insert/collRecs_v1.02.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.03" => "db_insert/collRecs_v1.03.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.05" => "db_insert/collRecs_v1.05.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.07/" => "db_insert/collRecs_v1.07.xsd",
    "http://ns.nsdl.org/db_insert/collRecs_v1.08/" => "db_insert/collRecs_v1.08.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs" => "db_insert/itemRecs_v0.0.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v0.1" => "db_insert/itemRecs_v0.1.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.00" => "db_insert/itemRecs_v1.00.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.01" => "db_insert/itemRecs_v1.01.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.02" => "db_insert/itemRecs_v1.02.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.03" => "db_insert/itemRecs_v1.03.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.04" => "db_insert/itemRecs_v1.04.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.05" => "db_insert/itemRecs_v1.05.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.06" => "db_insert/itemRecs_v1.06.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.07/" => "db_insert/itemRecs_v1.07.xsd",
    "http://ns.nsdl.org/db_insert/itemRecs_v1.08/" => "db_insert/itemRecs_v1.08.xsd",
    "http://ns.nsdl.org/ed_type_v1.00/" => "ed_type/ed_type_v1.00.xsd",
    "http://ns.nsdl.org/ed_type_v1.01/" => "ed_type/ed_type_v1.01.xsd",
    "http://ns.nsdl.org/gem_type_v0.1" => "gem_type/gem_type_v0.1.xsd",
    "http://ns.nsdl.org/gem_type_v1.00" => "gem_type/gem_type_v1.00.xsd",
    "http://ns.nsdl.org/gem_type_v1.01/" => "gem_type/gem_type_v1.01.xsd",
    "http://ns.nsdl.org/handle_type_v1.00/" => "handle_type/handle_type_v1.00.xsd",
    "http://ns.nsdl.org/mime_type_v1.00" => "mime_type/mime_type_v1.00.xsd",
    "http://ns.nsdl.org/MRingest/crsd_v1.02/" => "MRingest/crsd_v1.02.xsd",
    "http://ns.nsdl.org/MRingest/crsd_v1.03/" => "MRingest/crsd_v1.03.xsd",
    "http://ns.nsdl.org/MRingest/crsd_v1.04/" => "MRingest/crsd_v1.04.xsd",
    "http://ns.nsdl.org/MRingest/crsd_v1.05/" => "MRingest/crsd_v1.05.xsd",
    "http://ns.nsdl.org/MRingest/crsd_v1.06/" => "MRingest/crsd_v1.06.xsd",
    # "http://ns.nsdl.org/MRingest/harvest_v1.00/" => "MRingest/harvest_v1.00.xsd",
    "http://ns.nsdl.org/MRingest/harvest_v1.00/" => "ndr/ndr_ingest.xsd",
    "http://ns.nsdl.org/MRingest/harvest_v1.01/" => "MRingest/harvest_v1.01.xsd",
    "http://ns.nsdl.org/ndr/api_components_v1.00" => "ndr/api_components_v1.00.xsd",
    "http://ns.nsdl.org/ndr/auth#" => "ndr/ndr_auth_v1.00.xsd",
    "http://ns.nsdl.org/ndr/collections#" => "ndr/ndr_crs.xsd",
    "http://ns.nsdl.org/ndr/ndrTypes_v1.00/" => "ndr/ndrTypes_v1.00.xsd",
    # "http://ns.nsdl.org/ndr/request_v1.00/" => "ndr/request_types_v1.00.xsd",
    "http://ns.nsdl.org/ndr/request_v1.00/" => "ndr/request_v1.00.xsd",
    "http://ns.nsdl.org/ndr/response_v1.00/" => "ndr/response_v1.00.xsd",
    "http://ns.nsdl.org/nsdl_about_v0.1" => "nsdl_about/nsdl_about_v0.1.xsd",
    "http://ns.nsdl.org/nsdl_about_v1.00" => "nsdl_about/nsdl_about_v1.00.xsd",
    "http://ns.nsdl.org/nsdl_all" => "nsdl_all/nsdl_all_v0.0.xsd",
    "http://ns.nsdl.org/nsdl_all_v0.1" => "nsdl_all/nsdl_all_v0.1.xsd",
    "http://ns.nsdl.org/nsdl_all_v1.00" => "nsdl_all/nsdl_all_v1.00.xsd",
    "http://ns.nsdl.org/nsdl_all_v1.01" => "nsdl_all/nsdl_all_v1.01.xsd",
    "http://ns.nsdl.org/nsdl_all_v1.02/" => "nsdl_all/nsdl_all_v1.02.xsd",
    "http://ns.nsdl.org/nsdl_dc" => "nsdl_dc/nsdl_dc_v0.0.xsd",
    "http://ns.nsdl.org/nsdl_dc_v0.1" => "nsdl_dc/nsdl_dc_v0.1.xsd",
    "http://ns.nsdl.org/nsdl_dc_v1.00" => "nsdl_dc/nsdl_dc_v1.00.xsd",
    "http://ns.nsdl.org/nsdl_dc_v1.01" => "nsdl_dc/nsdl_dc_v1.01.xsd",
    "http://ns.nsdl.org/nsdl_dc_v1.02/" => "nsdl_dc/nsdl_dc_v1.02.xsd",
    "http://ns.nsdl.org/nsdl_dc_v1.03/" => "nsdl_dc/nsdl_dc_v1.03.xsd",
    "http://ns.nsdl.org/nsdl_links" => "nsdl_links/nsdl_links_v0.0.xsd",
    "http://ns.nsdl.org/nsdl_links_v0.1" => "nsdl_links/nsdl_links_v0.1.xsd",
    "http://ns.nsdl.org/nsdl_links_v1.00" => "nsdl_links/nsdl_links_v1.00.xsd",
    "http://ns.nsdl.org/nsdl_search" => "nsdl_search/nsdl_search_v0.0.xsd",
    "http://ns.nsdl.org/nsdl_search_v0.1" => "nsdl_search/nsdl_search_v0.1.xsd",
    "http://ns.nsdl.org/nsdl_search_v1.00" => "nsdl_search/nsdl_search_v1.00.xsd",
    "http://ns.nsdl.org/nsdl_search_v1.01" => "nsdl_search/nsdl_search_v1.01.xsd",
    "http://ns.nsdl.org/nsdl_search_v1.02/" => "nsdl_search/nsdl_search_v1.02.xsd",
    "http://ns.nsdl.org/nsdl_types_v0.1" => "nsdl_types/nsdl_types_v0.1.xsd",
    "http://ns.nsdl.org/nsdl_types_v1.00" => "nsdl_types/nsdl_types_v1.00.xsd",
    "http://ns.nsdl.org/nsdltype_v1.00" => "nsdltype/nsdltype_v1.00.xsd",
    "http://ns.nsdl.org/oai/nsdl_about" => "nsdl_about/nsdl_about_v0.0.xsd",
    "http://ns.nsdl.org/oai/provenance_about" => "provenance_about/provenance_about_v0.0.xsd",
    "http://ns.nsdl.org/partnerID_type_v1.00/" => "partnerID_type/partnerID_type_v1.00.xsd",
    "http://ns.nsdl.org/provenance_about_v0.1" => "provenance_about/provenance_about_v0.1.xsd",
    "http://ns.nsdl.org/provenance_about_v1.00" => "provenance_about/provenance_about_v1.00.xsd",
    "http://ns.nsdl.org/provenance_about_v1.01" => "provenance_about/provenance_about_v1.01.xsd",
    "http://ns.nsdl.org/schemas/MRingest/crsd_v1.00/" => "MRingest/crsd_v1.00.xsd",
    "http://ns.nsdl.org/schemas/MRingest/crsd_v1.01/" => "MRingest/crsd_v1.01.xsd",
    "http://ns.nsdl.org/search/rest_v1.00/" => "search/rest_v1.00.xsd",
    "http://ns.nsdl.org/search/rest_v1.01/" => "search/rest_v1.01.xsd",
    "http://ns.nsdl.org/search/rest_v1.02/" => "search/rest_v1.02.xsd",
    "http://ns.nsdl.org/search/rest_v2.00/" => "search/rest_v2.00.xsd",
    "http://ns.nsdl.org/search/rest_v2.01/" => "search/rest_v2.01.xsd",
    # "http://purl.org/dc/dcmitype/" => "dc/dcmitype_v0.0.xsd",
    # "http://purl.org/dc/dcmitype/" => "dc/dcmitype_v0.1.xsd",
    "http://purl.org/dc/dcmitype/" => "dc/dcmitype_v1.00.xsd",
    # "http://purl.org/dc/elements/1.1/" => "dc/dc_v0.0.xsd",
    # "http://purl.org/dc/elements/1.1/" => "dc/dc_v0.1.xsd",
    "http://purl.org/dc/elements/1.1/" => "dc/dc_v1.00.xsd",
    # "http://purl.org/dc/terms/" => "dc/dcterms_v0.0.xsd",
    # "http://purl.org/dc/terms/" => "dc/dcterms_v0.1.xsd",
    # "http://purl.org/dc/terms/" => "dc/dcterms_v1.00.xsd",
    "http://purl.org/dc/terms/" => "dc/dcterms_v1.01.xsd",
    "http://www.ieee.org/xsd/LOMv1p0" => "dc/ieee_lom_v0.01.xsd"
];
