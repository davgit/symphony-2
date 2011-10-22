<?php
	/**
	 * @package content
	 */

	/**
	 * The Publish page is where the majority of an Authors time will
	 * be spent in Symphony with adding, editing and removing entries
	 * from Sections. This Page controls the entries table as well as
	 * the Entry creation screens.
	 */
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	require_once(TOOLKIT . '/class.sectionmanager.php');
	require_once(CONTENT . '/class.sortable.php');

	Class contentPublish extends AdministrationPage{

		public $_errors = array();

		public function sort(&$sort, &$order, $params) {
			$section = $params['current-section'];

			if(is_null($sort)){
				$sort = $section->getDefaultSortingField();
			}

			if(is_numeric($sort)){
				if($section->get('entry_order') != $sort || $section->get('entry_order_direction') != $order){
					SectionManager::edit(
						$section->get('id'),
						array('entry_order' => $sort, 'entry_order_direction' => $order)
					);

					$query = '?sort=' . $sort . '&order=' . $order;

					redirect(Administration::instance()->getCurrentPageURL() . $query . $params['filters']);
				}
			}
			else if($sort == 'id'){
				EntryManager::setFetchSortingField('id');
				EntryManager::setFetchSortingDirection($order);
			}
		}

		public function action(){
			$this->__switchboard('action');
		}

		public function __switchboard($type='view'){

			$function = ($type == 'action' ? '__action' : '__view') . ucfirst($this->_context['page']);

			if(!method_exists($this, $function)) {
				// If there is no action function, just return without doing anything
				if($type == 'action') return;

				Administration::instance()->errorPageNotFound();
			}

			$this->$function();
		}

		public function view(){
			$this->__switchboard();
		}

		public function __viewIndex(){
			if(!$section_id = SectionManager::fetchIDFromHandle($this->_context['section_handle'])) {
				Administration::instance()->customError(__('Unknown Section'), __('The Section you are looking, <code>%s</code> for could not be found.', array($this->_context['section_handle'])));
			}

			$section = SectionManager::fetch($section_id);

			$this->setPageType('table');
			$this->setTitle(__('%1$s &ndash; %2$s', array($section->get('name'), __('Symphony'))));
			$this->Form->setAttribute("class", $this->_context['section_handle']);

			$filters = array();
			$filter_querystring = $prepopulate_querystring = $where = $joins = NULL;
			$current_page = (isset($_REQUEST['pg']) && is_numeric($_REQUEST['pg']) ? max(1, intval($_REQUEST['pg'])) : 1);

			if(isset($_REQUEST['filter'])) {

				// legacy implementation, convert single filter to an array
				// split string in the form ?filter=handle:value
				if(!is_array($_REQUEST['filter'])) {
					list($field_handle, $filter_value) = explode(':', $_REQUEST['filter'], 2);
					$filters[$field_handle] = rawurldecode($filter_value);
				} else {
					$filters = $_REQUEST['filter'];
				}

				foreach($filters as $handle => $value) {
					$field_id = Symphony::Database()->fetchVar('id', 0, sprintf("
						SELECT `f`.`id`
						FROM `tbl_fields` AS `f`
						LEFT JOIN `tbl_sections` AS `s` ON (`s`.`id` = `f`.`parent_section`)
						WHERE f.`element_name` = '%s'
						AND `s`.`handle` = '%s'
						LIMIT 1
					",
						Symphony::Database()->cleanValue($handle),
						$section->get('handle'))
					);

					$field = FieldManager::fetch($field_id);

					if($field instanceof Field) {
						// For deprecated reasons, call the old, typo'd function name until the switch to the
						// properly named buildDSRetrievalSQL function.
						$field->buildDSRetrivalSQL(array($value), $joins, $where, false);
						$filter_querystring .= sprintf("filter[%s]=%s&amp;", $handle, rawurlencode($value));
						$prepopulate_querystring .= sprintf("prepopulate[%d]=%s&amp;", $field_id, rawurlencode($value));
					} else {
						unset($filters[$i]);
					}
				}

				$filter_querystring = preg_replace("/&amp;$/", '', $filter_querystring);
				$prepopulate_querystring = preg_replace("/&amp;$/", '', $prepopulate_querystring);

			}

			Sortable::init($this, $entries, $sort, $order, array(
				'current-section' => $section,
				'filters' => ($filter_querystring ? "&amp;" . $filter_querystring : '')
			));

			$this->Form->setAttribute('action', Administration::instance()->getCurrentPageURL(). '?pg=' . $current_page.($filter_querystring ? "&amp;" . $filter_querystring : ''));

			$this->appendSubheading($section->get('name'), array(
				Widget::Anchor(__('Edit Section'), SYMPHONY_URL . '/blueprints/sections/edit/' . $section_id, __('Edit Section Configuration'), 'button'),
				Widget::Anchor(__('Create New'), Administration::instance()->getCurrentPageURL().'new/'.($filter_querystring ? '?' . $prepopulate_querystring : ''), __('Create a new entry'), 'create button', NULL, array('accesskey' => 'c'))
			));

			$entries = EntryManager::fetchByPage($current_page, $section_id, Symphony::Configuration()->get('pagination_maximum_rows', 'symphony'), $where, $joins);

			$visible_columns = $section->fetchVisibleColumns();
			$columns = array();

			if(is_array($visible_columns) && !empty($visible_columns)){

				foreach($visible_columns as $column){
					$columns[] = array(
						'label' => $column->label(),
						'sortable' => $column->isSortable(),
						'handle' => $column->get('id'),
						'attrs' => array(
							'id' => 'field-' . $column->get('id'),
							'class' => 'field-' . $column->get('type')
						)
					);
				}
			}
			else {
				$columns[] = array(
					'label' => __('ID'),
					'sortable' => true,
					'handle' => 'id'
				);
			}

			$aTableHead = Sortable::buildTableHeaders(
				$columns, $sort, $order,
				/* '&amp;pg='. $current_page . */ ($filter_querystring ? "&amp;" . $filter_querystring : '')
			);

			$child_sections = array();
			$associated_sections = $section->fetchAssociatedSections(true);
			if(is_array($associated_sections) && !empty($associated_sections)){
				foreach($associated_sections as $key => $as){
					$child_sections[$key] = SectionManager::fetch($as['child_section_id']);
					$aTableHead[] = array($child_sections[$key]->get('name'), 'col');
				}
			}

			/**
			 * Allows the creation of custom entries tablecolumns. Called
			 * after all the Section Visible columns have been added  as well
			 * as the Section Associations
			 *
			 * @delegate AddCustomPublishColumn
			 * @since Symphony 2.2
			 * @param string $context
			 * '/publish/'
			 * @param array $tableHead
			 * An array of the current columns, passed by reference
			 * @param integer $section_id
			 * The current Section ID
			 */
			Symphony::ExtensionManager()->notifyMembers('AddCustomPublishColumn', '/publish/', array('tableHead' => &$aTableHead, 'section_id' => $section->get('id')));

			// Table Body
			$aTableBody = array();

			if(!is_array($entries['records']) || empty($entries['records'])){

				$aTableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None found.'), 'inactive', NULL, count($aTableHead))), 'odd')
				);
			}
			else {

				$field_pool = array();
				if(is_array($visible_columns) && !empty($visible_columns)){
					foreach($visible_columns as $column){
						$field_pool[$column->get('id')] = $column;
					}
				}
				$link_column = end(array_reverse($visible_columns));
				reset($visible_columns);

				foreach($entries['records'] as $entry) {
					$tableData = array();

					// Setup each cell
					if(!is_array($visible_columns) || empty($visible_columns)) {
						$tableData[] = Widget::TableData(Widget::Anchor($entry->get('id'), Administration::instance()->getCurrentPageURL() . 'edit/' . $entry->get('id') . '/'));
					}
					else {
						$link = Widget::Anchor(
							__('None'),
							Administration::instance()->getCurrentPageURL() . 'edit/' . $entry->get('id') . '/',
							$entry->get('id'),
							'content'
						);

						foreach ($visible_columns as $position => $column) {
							$data = $entry->getData($column->get('id'));
							$field = $field_pool[$column->get('id')];

							$value = $field->prepareTableValue($data, ($column == $link_column) ? $link : null, $entry->get('id'));

							if (!is_object($value) && (strlen(trim($value)) == 0 || $value == __('None'))) {
								$value = ($position == 0 ? $link->generate() : __('None'));
							}

							if ($value == __('None')) {
								$tableData[] = Widget::TableData($value, 'inactive field-' . $column->get('type') . ' field-' . $column->get('id'));
							}
							else {
								$tableData[] = Widget::TableData($value, 'field-' . $column->get('type') . ' field-' . $column->get('id'));
							}

							unset($field);
						}
					}

					if(is_array($child_sections) && !empty($child_sections)){
						foreach($child_sections as $key => $as){

							$field = FieldManager::fetch((int)$associated_sections[$key]['child_section_field_id']);

							$parent_section_field_id = (int)$associated_sections[$key]['parent_section_field_id'];

							if(!is_null($parent_section_field_id)){
								$search_value = $field->fetchAssociatedEntrySearchValue(
									$entry->getData($parent_section_field_id),
									$parent_section_field_id,
									$entry->get('id')
								);
							}
							else {
								$search_value = $entry->get('id');
							}

							if(!is_array($search_value)) {
								$associated_entry_count = $field->fetchAssociatedEntryCount($search_value);

								$tableData[] = Widget::TableData(
									Widget::Anchor(
										sprintf('%d &rarr;', max(0, intval($associated_entry_count))),
										sprintf(
											'%s/publish/%s/?filter=%s:%s',
											SYMPHONY_URL,
											$as->get('handle'),
											$field->get('element_name'),
											rawurlencode($search_value)
										),
										$entry->get('id'),
										'content')
								);
							}
						}
					}

					/**
					 * Allows Extensions to inject custom table data for each Entry
					 * into the Publish Index
					 *
					 * @delegate AddCustomPublishColumnData
					 * @since Symphony 2.2
					 * @param string $context
					 * '/publish/'
					 * @param array $tableData
					 *	An array of `Widget::TableData`, passed by reference
					 * @param integer $section_id
					 *	The current Section ID
					 * @param integer $entry_id
					 *	The Entry ID for this row
					 */
					Symphony::ExtensionManager()->notifyMembers('AddCustomPublishColumnData', '/publish/', array('tableData' => &$tableData, 'section_id' => $section->get('id'), 'entry_id' => $entry));

					$tableData[count($tableData) - 1]->appendChild(Widget::Input('items['.$entry->get('id').']', NULL, 'checkbox'));

					// Add a row to the body array, assigning each cell to the row
					$aTableBody[] = Widget::TableRow($tableData, NULL, 'id-' . $entry->get('id'));
				}
			}

			$table = Widget::Table(
				Widget::TableHead($aTableHead),
				NULL,
				Widget::TableBody($aTableBody),
				'selectable'
			);

			$this->Form->appendChild($table);

			$tableActions = new XMLElement('div');
			$tableActions->setAttribute('class', 'actions');

			$options = array(
				array(NULL, false, __('With Selected...')),
				array('delete', false, __('Delete'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to delete the selected entries?')
				))
			);

			$toggable_fields = $section->fetchToggleableFields();

			if (is_array($toggable_fields) && !empty($toggable_fields)) {
				$index = 2;

				foreach ($toggable_fields as $field) {
					$options[$index] = array('label' => __('Set %s', array($field->get('label'))), 'options' => array());

					foreach ($field->getToggleStates() as $value => $state) {
						$options[$index]['options'][] = array('toggle-' . $field->get('id') . '-' . $value, false, $state);
					}

					$index++;
				}
			}

			$tableActions->appendChild(Widget::Select('with-selected', $options));
			$tableActions->appendChild(Widget::Input('action[apply]', __('Apply'), 'submit'));

			$this->Form->appendChild($tableActions);

			if($entries['total-pages'] > 1){
				$ul = new XMLElement('ul');
				$ul->setAttribute('class', 'page');

				// First
				$li = new XMLElement('li');
				if($current_page > 1) $li->appendChild(Widget::Anchor(__('First'), Administration::instance()->getCurrentPageURL(). '?pg=1'.($filter_querystring ? "&amp;" . $filter_querystring : '')));
				else $li->setValue(__('First'));
				$ul->appendChild($li);

				// Previous
				$li = new XMLElement('li');
				if($current_page > 1) $li->appendChild(Widget::Anchor(__('&larr; Previous'), Administration::instance()->getCurrentPageURL(). '?pg=' . ($current_page - 1).($filter_querystring ? "&amp;" . $filter_querystring : '')));
				else $li->setValue(__('&larr; Previous'));
				$ul->appendChild($li);

				// Summary
				$li = new XMLElement('li');
				
				$li->setAttribute('title', __('Viewing %1$s - %2$s of %3$s entries', array(
					$entries['start'],
					($current_page != $entries['total-pages']) ? $current_page * Symphony::Configuration()->get('pagination_maximum_rows', 'symphony') : $entries['total-entries'],
					$entries['total-entries']
				)));
				
				$pgform = Widget::Form(Administration::instance()->getCurrentPageURL(),'get','paginationform');
				$pgform->setValue(__('Page %1$s of <span>%2$s</span>', array(Widget::Input('pg',$current_page)->generate(), max($current_page, $entries['total-pages']))));
				
				$li->appendChild($pgform);
				$ul->appendChild($li);

				// Next
				$li = new XMLElement('li');
				if($current_page < $entries['total-pages']) $li->appendChild(Widget::Anchor(__('Next &rarr;'), Administration::instance()->getCurrentPageURL(). '?pg=' . ($current_page + 1).($filter_querystring ? "&amp;" . $filter_querystring : '')));
				else $li->setValue(__('Next &rarr;'));
				$ul->appendChild($li);

				// Last
				$li = new XMLElement('li');
				if($current_page < $entries['total-pages']) $li->appendChild(Widget::Anchor(__('Last'), Administration::instance()->getCurrentPageURL(). '?pg=' . $entries['total-pages'].($filter_querystring ? "&amp;" . $filter_querystring : '')));
				else $li->setValue(__('Last'));
				$ul->appendChild($li);

				$this->Contents->appendChild($ul);
			}
		}

		public function __actionIndex(){
			$checked = (is_array($_POST['items'])) ? array_keys($_POST['items']) : null;

			if(is_array($checked) && !empty($checked)){
				switch($_POST['with-selected']) {

					case 'delete':

						/**
						 * Prior to deletion of entries. Array of Entry ID's is provided.
						 * The array can be manipulated
						 *
						 * @delegate Delete
						 * @param string $context
						 * '/publish/'
						 * @param array $checked
						 *	An array of Entry ID's passed by reference
						 */
						Symphony::ExtensionManager()->notifyMembers('Delete', '/publish/', array('entry_id' => &$checked));

						EntryManager::delete($checked);

						redirect($_SERVER['REQUEST_URI']);

					default:

						list($option, $field_id, $value) = explode('-', $_POST['with-selected'], 3);

						if($option == 'toggle'){

							$field = FieldManager::fetch($field_id);
							$fields = array($field->get('element_name') => $value);

							$section = SectionManager::fetch($field->get('parent_section'));

							foreach($checked as $entry_id){
								$entry = EntryManager::fetch($entry_id);

								/**
								 * Just prior to editing of an Entry
								 *
								 * @delegate EntryPreEdit
								 * @param string $context
								 * '/publish/edit/'
								 * @param Section $section
								 * @param Entry $entry
								 * @param array $fields
								 */
								Symphony::ExtensionManager()->notifyMembers('EntryPreEdit', '/publish/edit/', array('section' => $section, 'entry' => &$entry[0], 'fields' => $fields));

								$entry[0]->setData($field_id, $field->toggleFieldData($entry[0]->getData($field_id), $value, $entry_id));
								$entry[0]->commit();

								/**
								 * Editing an entry. Entry object is provided.
								 *
								 * @delegate EntryPostEdit
								 * @param string $context
								 * '/publish/edit/'
								 * @param Section $section
								 * @param Entry $entry
								 * @param array $fields
								 */
								Symphony::ExtensionManager()->notifyMembers('EntryPostEdit', '/publish/edit/', array('section' => $section, 'entry' => $entry[0], 'fields' => $fields));
							}

							redirect($_SERVER['REQUEST_URI']);

						}

						break;
				}
			}
		}

		public function __viewNew() {
			if(!$section_id = SectionManager::fetchIDFromHandle($this->_context['section_handle'])) {
				Administration::instance()->customError(__('Unknown Section'), __('The Section you are looking, <code>%s</code> for could not be found.', array($this->_context['section_handle'])));
			}

			$section = SectionManager::fetch($section_id);

			$this->setPageType('form');
			$this->Form->setAttribute('enctype', 'multipart/form-data');
			$this->setTitle(__('%1$s &ndash; %2$s', array($section->get('name'), __('Symphony'))));
			$this->appendSubheading(__('Untitled'),
				Widget::Anchor(__('Edit Section'), SYMPHONY_URL . '/blueprints/sections/edit/' . $section_id, __('Edit Section Configuration'), 'button')
			);
			$this->insertBreadcrumbs(array(
				Widget::Anchor($section->get('name'), SYMPHONY_URL . '/publish/' . $this->_context['section_handle']),
			));

			$this->Form->appendChild(Widget::Input('MAX_FILE_SIZE', Symphony::Configuration()->get('max_upload_size', 'admin'), 'hidden'));

			// If there is post data floating around, due to errors, create an entry object
			if (isset($_POST['fields'])) {
				$entry = EntryManager::create();
				$entry->set('section_id', $section_id);
				$entry->setDataFromPost($_POST['fields'], $error, true);
			}

			// Brand new entry, so need to create some various objects
			else {
				$entry = EntryManager::create();
				$entry->set('section_id', $section_id);
			}

			// Check if there is a field to prepopulate
			if (isset($_REQUEST['prepopulate'])) {
				foreach($_REQUEST['prepopulate'] as $field_id => $value) {
					$this->Form->prependChild(Widget::Input(
						"prepopulate[{$field_id}]",
						rawurlencode($value),
						'hidden'
					));

					// The actual pre-populating should only happen if there is not existing fields post data
					if(!isset($_POST['fields']) && $field = FieldManager::fetch($field_id)) {
						$entry->setData(
							$field->get('id'),
							$field->processRawFieldData($value, $error, true)
						);
					}
				}
			}

			$primary = new XMLElement('fieldset');
			$primary->setAttribute('class', 'primary');

			$sidebar_fields = $section->fetchFields(NULL, 'sidebar');
			$main_fields = $section->fetchFields(NULL, 'main');

			if ((!is_array($main_fields) || empty($main_fields)) && (!is_array($sidebar_fields) || empty($sidebar_fields))) {
				$primary->appendChild(new XMLElement('p', __(
					'It looks like you\'re trying to create an entry. Perhaps you want fields first? <a href="%s">Click here to create some.</a>',
					array(
						SYMPHONY_URL . '/blueprints/sections/edit/' . $section->get('id') . '/'
					)
				)));
				$this->Form->appendChild($primary);
			}

			else {
				if (is_array($main_fields) && !empty($main_fields)) {
					foreach ($main_fields as $field) {
						$primary->appendChild($this->__wrapFieldWithDiv($field, $entry));
					}

					$this->Form->appendChild($primary);
				}

				if (is_array($sidebar_fields) && !empty($sidebar_fields)) {
					$sidebar = new XMLElement('fieldset');
					$sidebar->setAttribute('class', 'secondary');

					foreach ($sidebar_fields as $field) {
						$sidebar->appendChild($this->__wrapFieldWithDiv($field, $entry));
					}

					$this->Form->appendChild($sidebar);
				}
			}

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(Widget::Input('action[save]', __('Create Entry'), 'submit', array('accesskey' => 's')));

			$this->Form->appendChild($div);
		}

		public function __actionNew(){

			if(array_key_exists('save', $_POST['action']) || array_key_exists("done", $_POST['action'])) {

				$section_id = SectionManager::fetchIDFromHandle($this->_context['section_handle']);

				if(!$section = SectionManager::fetch($section_id)) {
					Administration::instance()->customError(__('Unknown Section'), __('The Section you are looking, <code>%s</code> for could not be found.', $this->_context['section_handle']));
				}

				$entry =& EntryManager::create();
				$entry->set('section_id', $section_id);
				$entry->set('author_id', Administration::instance()->Author->get('id'));
				$entry->set('creation_date', DateTimeObj::get('Y-m-d H:i:s'));
				$entry->set('creation_date_gmt', DateTimeObj::getGMT('Y-m-d H:i:s'));

				$fields = $_POST['fields'];

				// Combine FILES and POST arrays, indexed by their custom field handles
				if(isset($_FILES['fields'])){
					$filedata = General::processFilePostData($_FILES['fields']);

					foreach($filedata as $handle => $data){
						if(!isset($fields[$handle])) $fields[$handle] = $data;
						elseif(isset($data['error']) && $data['error'] == 4) $fields['handle'] = NULL;
						else{

							foreach($data as $ii => $d){
								if(isset($d['error']) && $d['error'] == 4) $fields[$handle][$ii] = NULL;
								elseif(is_array($d) && !empty($d)){

									foreach($d as $key => $val)
										$fields[$handle][$ii][$key] = $val;
								}
							}
						}
					}
				}

				if(__ENTRY_FIELD_ERROR__ == $entry->checkPostData($fields, $this->_errors)):
					$this->pageAlert(__('Some errors were encountered while attempting to save.'), Alert::ERROR);

				elseif(__ENTRY_OK__ != $entry->setDataFromPost($fields, $error)):
					$this->pageAlert($error['message'], Alert::ERROR);

				else:
					/**
					 * Just prior to creation of an Entry
					 *
					 * @delegate EntryPreCreate
					 * @param string $context
					 * '/publish/new/'
					 * @param Section $section
					 * @param Entry $entry
					 * @param array $fields
					 */
					Symphony::ExtensionManager()->notifyMembers('EntryPreCreate', '/publish/new/', array('section' => $section, 'entry' => &$entry, 'fields' => &$fields));

					if(!$entry->commit()){
						define_safe('__SYM_DB_INSERT_FAILED__', true);
						$this->pageAlert(NULL, Alert::ERROR);

					}

					else{
						/**
						 * Creation of an Entry. New Entry object is provided.
						 *
						 * @delegate EntryPostCreate
						 * @param string $context
						 * '/publish/new/'
						 * @param Section $section
						 * @param Entry $entry
						 * @param array $fields
						 */
						Symphony::ExtensionManager()->notifyMembers('EntryPostCreate', '/publish/new/', array('section' => $section, 'entry' => $entry, 'fields' => $fields));

						$prepopulate_querystring = '';
						if(isset($_POST['prepopulate'])){
							foreach($_POST['prepopulate'] as $field_id => $value) {
								$prepopulate_querystring .= sprintf("prepopulate[%s]=%s&", $field_id, rawurldecode($value));
							}
							$prepopulate_querystring = trim($prepopulate_querystring, '&');
						}

						redirect(sprintf(
							'%s/publish/%s/edit/%d/created/%s',
							SYMPHONY_URL,
							$this->_context['section_handle'],
							$entry->get('id'),
							(!empty($prepopulate_querystring) ? "?" . $prepopulate_querystring : NULL)
						));

					}

				endif;
			}

		}

		public function __viewEdit() {
			if(!$section_id = SectionManager::fetchIDFromHandle($this->_context['section_handle'])) {
				Administration::instance()->customError(__('Unknown Section'), __('The Section you are looking for, <code>%s</code>, could not be found.', array($this->_context['section_handle'])));
			}

			$section = SectionManager::fetch($section_id);

			$entry_id = intval($this->_context['entry_id']);

			EntryManager::setFetchSorting('id', 'DESC');

			if(!$existingEntry = EntryManager::fetch($entry_id)) {
				Administration::instance()->customError(__('Unknown Entry'), __('The entry you are looking for could not be found.'));
			}
			$existingEntry = $existingEntry[0];

			// If there is post data floating around, due to errors, create an entry object
			if (isset($_POST['fields'])) {
				$fields = $_POST['fields'];

				$entry =& EntryManager::create();
				$entry->set('section_id', $existingEntry->get('section_id'));
				$entry->set('id', $entry_id);

				$entry->setDataFromPost($fields, $error, true);
			}

			// Editing an entry, so need to create some various objects
			else {
				$entry = $existingEntry;

				if (!$section) {
					$section = SectionManager::fetch($entry->get('section_id'));
				}
			}

			/**
			 * Just prior to rendering of an Entry edit form.
			 *
			 * @delegate EntryPreRender
			 * @param string $context
			 * '/publish/new/'
			 * @param Section $section
			 * @param Entry $entry
			 * @param array $fields
			 */
			Symphony::ExtensionManager()->notifyMembers('EntryPreRender', '/publish/edit/', array('section' => $section, 'entry' => &$entry, 'fields' => $fields));

			if(isset($this->_context['flag'])){

				$link = 'publish/'.$this->_context['section_handle'].'/new/';

				list($flag, $field_id, $value) = preg_split('/:/i', $this->_context['flag'], 3);

				if(isset($_REQUEST['prepopulate'])){
					$link .= '?';
					foreach($_REQUEST['prepopulate'] as $field_id => $value) {
						$link .= "prepopulate[$field_id]=$value&amp;";
					}
					$link = preg_replace("/&amp;$/", '', $link);
				}

				switch($flag){

					case 'saved':
						$this->pageAlert(
							__(
								'Entry updated at %1$s. <a href="%2$s" accesskey="c">Create another?</a> <a href="%3$s" accesskey="a">View all Entries</a>',
								array(
									DateTimeObj::getTimeAgo(__SYM_TIME_FORMAT__),
									SYMPHONY_URL . "/$link",
									SYMPHONY_URL . '/publish/'.$this->_context['section_handle'].'/'
								)
							),
							Alert::SUCCESS);

						break;

					case 'created':
						$this->pageAlert(
							__(
								'Entry created at %1$s. <a href="%2$s" accesskey="c">Create another?</a> <a href="%3$s" accesskey="a">View all Entries</a>',
								array(
									DateTimeObj::getTimeAgo(__SYM_TIME_FORMAT__),
									SYMPHONY_URL . "/$link",
									SYMPHONY_URL . '/publish/'.$this->_context['section_handle'].'/'
								)
							),
							Alert::SUCCESS);
						break;

				}
			}

			// Determine the page title
			$field_id = Symphony::Database()->fetchVar('id', 0, "SELECT `id` FROM `tbl_fields` WHERE `parent_section` = '".$section->get('id')."' ORDER BY `sortorder` LIMIT 1");
			$field = FieldManager::fetch($field_id);

			$title = trim(strip_tags($field->prepareTableValue($existingEntry->getData($field->get('id')), NULL, $entry_id)));

			if (trim($title) == '') {
				$title = __('Untitled');
			}

			// Check if there is a field to prepopulate
			if (isset($_REQUEST['prepopulate'])) {
				foreach($_REQUEST['prepopulate'] as $field_id => $value) {
					$this->Form->prependChild(Widget::Input(
						"prepopulate[{$field_id}]",
						rawurlencode($value),
						'hidden'
					));
				}
			}

			$this->setPageType('form');
			$this->Form->setAttribute('enctype', 'multipart/form-data');
			$this->setTitle(__('%1$s &ndash; %2$s &ndash; %3$s', array($title, $section->get('name'), __('Symphony'))));
			$this->appendSubheading($title,
				Widget::Anchor(__('Edit Section'), SYMPHONY_URL . '/blueprints/sections/edit/' . $section_id, __('Edit Section Configuration'), 'button')
			);
			$this->insertBreadcrumbs(array(
				Widget::Anchor($section->get('name'), SYMPHONY_URL . '/publish/' . $this->_context['section_handle']),
			));

			$this->Form->appendChild(Widget::Input('MAX_FILE_SIZE', Symphony::Configuration()->get('max_upload_size', 'admin'), 'hidden'));

			$primary = new XMLElement('fieldset');
			$primary->setAttribute('class', 'primary');

			$sidebar_fields = $section->fetchFields(NULL, 'sidebar');
			$main_fields = $section->fetchFields(NULL, 'main');

			if((!is_array($main_fields) || empty($main_fields)) && (!is_array($sidebar_fields) || empty($sidebar_fields))){
				$primary->appendChild(new XMLElement('p', __('It looks like you\'re trying to create an entry. Perhaps you want fields first? <a href="%s">Click here to create some.</a>', array(SYMPHONY_URL . '/blueprints/sections/edit/'. $section->get('id') . '/'))));
			}

			else{

				if(is_array($main_fields) && !empty($main_fields)){
					foreach($main_fields as $field){
						$primary->appendChild($this->__wrapFieldWithDiv($field, $entry));
					}

					$this->Form->appendChild($primary);
				}

				if(is_array($sidebar_fields) && !empty($sidebar_fields)){
					$sidebar = new XMLElement('fieldset');
					$sidebar->setAttribute('class', 'secondary');

					foreach($sidebar_fields as $field){
						$sidebar->appendChild($this->__wrapFieldWithDiv($field, $entry));
					}

					$this->Form->appendChild($sidebar);
				}

			}

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(Widget::Input('action[save]', __('Save Changes'), 'submit', array('accesskey' => 's')));

			$button = new XMLElement('button', __('Delete'));
			$button->setAttributeArray(array('name' => 'action[delete]', 'class' => 'button confirm delete', 'title' => __('Delete this entry'), 'type' => 'submit', 'accesskey' => 'd', 'data-message' => __('Are you sure you want to delete this entry?')));
			$div->appendChild($button);

			$this->Form->appendChild($div);

		}

		public function __actionEdit(){

			$entry_id = intval($this->_context['entry_id']);

			if(@array_key_exists('save', $_POST['action']) || @array_key_exists("done", $_POST['action'])){
				if(!$ret = EntryManager::fetch($entry_id)) {
					Administration::instance()->customError(__('Unknown Entry'), __('The entry you are looking for could not be found.'));
				}
				$entry = $ret[0];

				$section = SectionManager::fetch($entry->get('section_id'));

				$post = General::getPostData();
				$fields = $post['fields'];

				if(__ENTRY_FIELD_ERROR__ == $entry->checkPostData($fields, $this->_errors)):
					$this->pageAlert(__('Some errors were encountered while attempting to save.'), Alert::ERROR);

				elseif(__ENTRY_OK__ != $entry->setDataFromPost($fields, $error, true)):
					$this->pageAlert($error['message'], Alert::ERROR);

				else:

					/**
					 * Just prior to editing of an Entry.
					 *
					 * @delegate EntryPreEdit
					 * @param string $context
					 * '/publish/edit/'
					 * @param Section $section
					 * @param Entry $entry
					 * @param array $fields
					 */
					Symphony::ExtensionManager()->notifyMembers('EntryPreEdit', '/publish/edit/', array('section' => $section, 'entry' => &$entry, 'fields' => $fields));

					if(!$entry->commit()){
						define_safe('__SYM_DB_INSERT_FAILED__', true);
						$this->pageAlert(NULL, Alert::ERROR);

					}

					else {

						/**
						 * Just after the editing of an Entry
						 *
						 * @delegate EntryPostEdit
						 * @param string $context
						 * '/publish/edit/'
						 * @param Section $section
						 * @param Entry $entry
						 * @param array $fields
						 */
						Symphony::ExtensionManager()->notifyMembers('EntryPostEdit', '/publish/edit/', array('section' => $section, 'entry' => $entry, 'fields' => $fields));

						$prepopulate_querystring = '';
						if(isset($_POST['prepopulate'])){
							foreach($_POST['prepopulate'] as $field_id => $value) {
								$prepopulate_querystring .= sprintf("prepopulate[%s]=%s&", $field_id, $value);
							}
							$prepopulate_querystring = trim($prepopulate_querystring, '&');
						}

						redirect(sprintf(
							'%s/publish/%s/edit/%d/saved/%s',
							SYMPHONY_URL,
							$this->_context['section_handle'],
							$entry->get('id'),
							(!empty($prepopulate_querystring) ? "?" . $prepopulate_querystring : NULL)
						));
					}

				endif;
			}

			elseif(@array_key_exists('delete', $_POST['action']) && is_numeric($entry_id)){
				/**
				 * Prior to deletion of entries. Array of Entry ID's is provided.
				 * The array can be manipulated
				 *
				 * @delegate Delete
				 * @param string $context
				 * '/publish/'
				 * @param array $checked
				 *	An array of Entry ID's passed by reference
				 */
				$checked = array($entry_id);
				Symphony::ExtensionManager()->notifyMembers('Delete', '/publish/', array('entry_id' => &$checked));

				EntryManager::delete($checked);

				redirect(SYMPHONY_URL . '/publish/'.$this->_context['section_handle'].'/');
			}

		}

		/**
		 * Given a Field and Entry object, this function will wrap
		 * the Field's displayPublishPanel result with a div that
		 * contains some contextual information such as the Field ID,
		 * the Field handle and whether it is required or not.
		 *
		 * @param Field $field
		 * @param Entry $entry
		 * @return XMLElement
		 */
		private function __wrapFieldWithDiv(Field $field, Entry $entry){
			$div = new XMLElement('div', NULL, array('id' => 'field-' . $field->get('id'), 'class' => 'field field-'.$field->handle().($field->get('required') == 'yes' ? ' required' : '')));
			$field->displayPublishPanel(
				$div, $entry->getData($field->get('id')),
				(isset($this->_errors[$field->get('id')]) ? $this->_errors[$field->get('id')] : NULL),
				null, null, (is_numeric($entry->get('id')) ? $entry->get('id') : NULL)
			);
			return $div;
		}

	}
