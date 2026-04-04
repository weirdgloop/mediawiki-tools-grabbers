<?php

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\NameTableAccessException;

require_once __DIR__ . '/../maintenance/Maintenance.php';

class PopulateNameTables extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Populates the change_tag_def, content_models, and slot_roles tables.' );
	}

	public function execute() {
        $services = MediaWikiServices::getInstance();
        $this->populateTable(
            'change_tag_def',
            $services->getChangeTagDefStore(),
            $services->getChangeTagsStore()->listSoftwareDefinedTags(),
        );
        $this->populateTable(
            'content_models',
            $services->getContentModelStore(),
            $services->getContentHandlerFactory()->getContentModels(),
        );
        $this->populateTable(
            'slot_roles',
            $services->getSlotRoleStore(),
            $services->getSlotRoleRegistry()->getKnownRoles(),
        );
	}

    private function populateTable( $name, $store, $entries ) {
        $this->output( "Populating $name...\n" );
        $output = [];
        foreach ( $entries as $entry ) {
            $status = 'EXISTING';
            $id = 0;
            try {
                $id = $store->getId( $entry );
            } catch ( NameTableAccessException $e ) {
                $status = 'NEW';
                $id = $store->acquireId( $entry );
            }
            $output[$id] = "[$id] $entry: $status\n";
        }
        ksort( $output, SORT_NUMERIC );
        foreach ( $output as $line ) {
            $this->output( $line );
        }
        $this->output("\n");
    }
}

$maintClass = PopulateNameTables::class;
require_once RUN_MAINTENANCE_IF_MAIN;
