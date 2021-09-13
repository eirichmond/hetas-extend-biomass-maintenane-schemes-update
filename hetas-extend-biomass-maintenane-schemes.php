<?php
/**
 * Plugin Name:     Hetas Extend Biomass Maintenane Schemes
 * Plugin URI:      https://www.hetas.co.uk
 * Description:     This extends business updates for Biomass Maintenance schemes, a cron job should be setup to fire the action "update_biomass_maintenance_business" the separate update for HABMS buinesses.
 * Author:          Elliott Richmond
 * Author URI:      https://squareone.software
 * Text Domain:     hetas-extend-biomass-maintenane-schemes
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Hetas_Extend_Biomass_Maintenane_Schemes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


/**
 * Get qualified biomass maintenance schemes
 *
 * @return object $schemes->value
 */
function get_qualified_biomass_maintenance_schemes() {
	$call = new Dynamics_crm('crm','1.1.0');
	$schemes = $call->get_biomass_business_schemes();
	return $schemes->value;
}

/**
 * Get businesses linked to schemes
 *
 * @return object $business->value[0]
 */
function get_business_linked_to_scheme($id) {
	$call = new Dynamics_crm('crm','1.1.0');
	$business = $call->get_business_by_accountid_refined($id);
	return $business->value[0];
}

/**
 * Get rhi manufacturere linked to schemes
 *
 * @return object $rhi_manufacturers->value
 */
function get_rhi_manufactureres_linked_to_scheme($id) {
	$call = new Dynamics_crm('crm','1.1.0');
	$rhi_manufacturers = $call->get_rhi_manufacturers_by_scheme_id($id);
	return $rhi_manufacturers->value;
}

/**
 * Set currently published biomass maintenance businesses to unchecked against CRM
 *
 * @return void
 */

function set_biomass_maintenance_crm_not_checked() {
	global $wpdb; // this is how you get access to the database

	$results = $wpdb->get_results(
		"SELECT {$wpdb->prefix}posts.`ID`, {$wpdb->prefix}posts.`post_type`, {$wpdb->prefix}postmeta.`meta_key`
			FROM {$wpdb->prefix}posts
		JOIN {$wpdb->prefix}postmeta
			ON {$wpdb->prefix}posts.`ID` = {$wpdb->prefix}postmeta.`post_id`
		WHERE {$wpdb->prefix}posts.`post_type` = 'business'
		AND {$wpdb->prefix}postmeta.`meta_key` = 'van_schemetype'",
		OBJECT );
	foreach($results as $business) {
		update_post_meta($business->ID, '_crm_checked', '-999');
	}
}

/**
 * prepare compentencies for taxonomy object
 *
 * @param string $business_id
 * @return array $competency_array
 */
function get_biomass_business_competencies($business_id){
	$competency_array = array();
	$call = new Dynamics_crm('crm','1.1.0');
	$competencies = $call->get_competencies_by_bussiness_id($business_id);
	$competencies = $competencies->value;
	foreach ($competencies as $competency) {
		$competency_array[] = $competency->Competence_x002e_van_name;
	}
	return $competency_array;

}

/**
 * Update taxonoy
 *
 * @param int $post_id
 * @param string $accountid
 * @return void
 */
function update_biomass_business_competencies($post_id, $accountid) {
	// get all the compentencies associated with this busines and put them in an array
	$competency_array = get_biomass_business_competencies($accountid);
	wp_set_object_terms($post_id, $competency_array, 'competencies', false);
}

/**
 * Set CRM checked
 *
 * @param int $post_id
 * @return void
 */
function set_biomass_unchecked_to_unpublished($post_id) {
	// set as checked
	update_post_meta($post_id, '_crm_checked', '1');

}

function update_additional_biomass_meta($post_id, $scheme, $rhi_manufacturers) {
	$manufacturers = array();
	update_post_meta( $post_id, 'van_schemetype', $scheme->van_schemetype );
	update_post_meta( $post_id, 'van_schemeid', $scheme->van_schemeid );
	foreach($rhi_manufacturers as $k => $rhi_manufacturer) {
		$manufacturers[$k]['van_rhimanufacturerid'] = $rhi_manufacturer->van_rhimanufacturerid;
		$manufacturers[$k]['van_name'] = $rhi_manufacturer->van_name;
	}
	update_post_meta( $post_id, 'rhi_manufacturers', $manufacturers );
}

/**
 * Update/Create Biomass Business posts and supporting data
 *
 * @param object $business
 * @param object $scheme
 * @param object $rhi_manufacturers
 * @return void
 */
function update_create_biomass_business($business, $scheme, $rhi_manufacturers){
	$hetas_dynamics_crm = new Hetas_Dynamics_crm_Public('Hetas_Dynamics_crm', '1.0.0');
	$crm_business_data_array = $hetas_dynamics_crm->prepare_business_data_as_array($business);

	$hetas_business = get_posts(array('post_status' => 'any', 'post_type' => 'business', 'meta_key' => 'inst_id', 'meta_value' => $business->van_hetasid));
	$hetas_business = $hetas_business[0];

	// add a new business if its statuscode is Approved
	if (!$hetas_business) {
		error_log('BMS log: bms do not exist so create it.');
		$new_business = array ('post_status' => 'draft', 'post_type' => 'business', 'post_title' => $business->name, 'post_name' => sanitize_title($business->name));
		$post_id = wp_insert_post($new_business, true);
		if (is_wp_error($post_id)) {
			error_log( $post_id->get_error_message() );
		}
		$hetas_dynamics_crm->update_business_postmeta($post_id, $crm_business_data_array);

		update_additional_biomass_meta($post_id, $scheme, $rhi_manufacturers);

		// set it to Publish if insta_display is true
		$hetas_dynamics_crm->check_business_public_display($post_id, $crm_business_data_array, $business);

		update_biomass_business_competencies($post_id, $business->accountid);

		set_biomass_unchecked_to_unpublished($post_id);

	}
	if ($hetas_business) {
		error_log('BMS log: bms does exist so update it.');

		$hetas_dynamics_crm->update_business_postmeta($hetas_business->ID, $crm_business_data_array);

		update_additional_biomass_meta($hetas_business->ID, $scheme, $rhi_manufacturers);

		// and set it to Publish if insta_display is true
		$hetas_dynamics_crm->check_business_public_display($hetas_business->ID, $crm_business_data_array, $business);

		// get all the compentencies associated with this busines and put them in an array
		$competency_array = get_biomass_business_competencies($business->accountid);
		update_biomass_business_competencies($hetas_business->ID, $business->accountid);

		set_biomass_unchecked_to_unpublished($hetas_business->ID);
	}

}


/**
 * Process and update biomass maintenance businesses
 *
 * @return void
 */
function update_biomass_maintenance_business_callback() {

	// check all biomass maintenance and set to crm not checked
	set_biomass_maintenance_crm_not_checked();

	$schemes = get_qualified_biomass_maintenance_schemes();
	foreach ($schemes as $scheme) {
		$business = get_business_linked_to_scheme($scheme->_van_businessid_value);
		$rhi_manufacturers = get_rhi_manufactureres_linked_to_scheme($scheme->van_schemeid);
		// create or update businesses
		update_create_biomass_business($business, $scheme, $rhi_manufacturers);
	}
	error_log('BMS log: update complete.');
	
}
add_action('update_biomass_maintenance_business', 'update_biomass_maintenance_business_callback');