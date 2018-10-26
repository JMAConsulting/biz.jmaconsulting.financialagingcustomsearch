<?php

/**
 * A custom contact search
 */
class CRM_FinancialAgingCustomSearch_Form_Search_FinancialAging extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;

  protected $_groupByColumns = [];

  protected $_whereClause = '';

  public function __construct(&$formValues) {
    parent::__construct($formValues);
    $this->_groupByColumns = array_keys(CRM_Utils_Array::value('group_bys', $formValues, []));
    $this->setColumns();
    CRM_Core_DAO::disableFullGroupByMode();
  }

  public function __destruct() {
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_customsearch');
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_groupcontacts');
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_membershipcontacts');
    CRM_Core_DAO::reenableFullGroupByMode();
  }

  public function buildForm(&$form) {
    $this->setTitle('Financial Aging with Pay Balances Details');

    if (!CRM_Core_Permission::check('access CiviContribute')) {
      $this->setTitle('Not Authorized');
      return;
    }

    $form->add('select', 'group_of_contact', ts('Contact is in the group'), CRM_Core_PseudoConstant::group(), FALSE,
       array('id' => 'group_of_contact', 'multiple' => 'multiple', 'title' => ts('-- select --'))
    );
    $form->add('select', 'member_of_contact_id', ts('Contact has Membership In'), self::getMembershipOrgs(), FALSE,
       array('class' => 'crm-select2', 'multiple' => 'multiple', 'placeholder' => ts('-- select --'))
    );
    $form->addEntityRef('membership_type_id', ts('Membership Type'), array(
     'entity' => 'MembershipType',
     'multiple' => TRUE,
     'placeholder' => ts('- any -'),
     'select' => array('minimumInputLength' => 0),
    ));
    $form->addDate('end_date', ts('Due By'), FALSE, array('formatType' => 'custom'));

    $form->addSelect('financial_type_id', ['entity' => 'contribution', 'multiple' => 'multiple']);

    $form->add('text', 'num_days_overdue', ts('Number Days Overdue'));

    $form->addSelect('preferred_communication_method', [
      'entity' => 'contact',
      'multiple' => 'multiple',
      'label' => ts('Preferred Communication Method'),
      'option_url' => NULL,
    ]);

    $form->assign('elements', array('group_of_contact', 'member_of_contact_id', 'membership_type_id', 'end_date', 'num_days_overdue', 'financial_type_id', 'preferred_communication_method'));

    $groupByElements = [
     ts('Contact ID') => 'contact_id',
     ts('Financial Type') => 'ft_name',
    ];
    $form->assign('group_by_elements', $groupByElements);
    $form->addCheckBox("group_bys", ts('Group by columns'), $groupByElements, NULL, NULL, NULL, NULL, ['<br/>']);
  }

  public function setColumns() {
    $this->_columns = [
     ts('Name') => 'sort_name',
     ts('0-30 Days') => 'days_30',
     ts('31-60 Days') => 'days_60',
     ts('61-90 Days') => 'days_90',
     ts('91 or more Days') => 'days_91_or_more',
     ts('Financial Type') => 'ft_name',
     ts('Financial Set') => 'ft_category',
     ts('Date Criteria') => 'date_parm',
     ts('Expected Date') => 'exp_date',
     ts('Currency') => 'currency',
     ts('ID') => 'line_id',
     ts('Paid') => 'paid',
     ts('Total Amount') => 'total_amount',
     ts('Days Overdue') => 'days_overdue',
     ts('Type') => 'entity_type',
     ts('Num. Records Combined') => 'num_records',
    ];
    if (count($this->_groupByColumns)) {
      unset($this->_columns[ts('ID')], $this->_columns[ts('Expected Date')]);
      if (count($this->_groupByColumns) == 1 && in_array('ft_name', $this->_groupByColumns)) {
        unset($this->_columns[ts('Name')]);
      }
    }
  }

  public function select($paymentType, $onlyIDs) {
    $select = [];
    $end_date_parm = CRM_Utils_Date::processDate($this->_formValues['end_date'], NULL, FALSE, 'Y-m-d') ?: 'CURDATE()';

    if ($paymentType == 'Pledge payment') {
      $selectColumns = $this->pledgePaymentSelectClause();
    }
    elseif ($paymentType == 'Recurring payment') {
      $selectColumns = $this->recurringPaymentSelectClause();
    }

    foreach ($selectColumns as $alias => $column) {
      $select[] = sprintf("%s as `%s`", $column, $alias);
    }

    return "SELECT " . implode(', ', $select);
  }

  public function from() {
    return " FROM temp_financialaging_customsearch temp ";
  }

  public function where($includeContactIDs = FALSE) {
    $whereClauses = ['(1)'];
    foreach (['preferred_communication_method' => 'c.preferred_communication_method IN (%s)'] as $filter => $dbColumn) {
      $value = CRM_Utils_Array::value($filter, $this->_formValues);
      if ($value) {
        if ($filter == 'end_date') {
          $whereClauses[] = sprintf($dbColumn, date('Y-m-d', strtotime($value)));
        }
        else {
          $whereClauses[] = sprintf($dbColumn, implode(",", $value));
        }
      }
    }
    return "WHERE " . implode(' AND ', $whereClauses);
  }

  public static function populateNextScheduleDate () {
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_recur_next_date');
    $pendingStatuses = implode(', ', [
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Overdue'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'),
    ]);
    CRM_Core_DAO::executeQuery(sprintf("CREATE TEMPORARY TABLE temp_recur_next_date DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci
      SELECT id, next_sched_contribution_date, modified_date, frequency_unit, frequency_interval
      FROM civicrm_contribution_recur
      WHERE contribution_status_id IN ($pendingStatuses)
    "));
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM temp_recur_next_date");
    while($dao->fetch()) {
      if (empty($dao->next_sched_contribution_date)) {
        $next_sched_contribution_date = date('Y-m-d', strtotime('+' . $dao->frequency_interval . ' ' .  $dao->frequency_unit, strtotime($dao->modified_date)));
        CRM_Core_DAO::executeQuery(sprintf("UPDATE temp_recur_next_date SET next_sched_contribution_date = '%s' WHERE id = %d ", $next_sched_contribution_date, $dao->id));
      }
    }
  }

  public function all($offset = 0, $rowcount = 0, $sort = NULL, $includeContactIDs = FALSE, $onlyIDs = FALSE) {
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_customsearch');
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_groupcontacts');
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_membershipcontacts');

    self::populateNextScheduleDate();

    $where = $this->where($includeContactIDs);

    $select = $this->select('Pledge payment', $onlyIDs);
    $from = $this->pledgePaymentFromClause();
    $PPsql = $select . $from . $where . ' AND c.id IS NOT NULL GROUP BY li.id ';

    $select = $this->select('Recurring payment', $onlyIDs);
    $from = $this->recurringPaymentFromClause();
    $RRsql = $select . $from . $where . ' GROUP BY cc.id ';

    CRM_Core_DAO::executeQuery("CREATE TEMPORARY TABLE temp_financialaging_customsearch DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci
     ($PPsql)
       UNION ALL
     ($RRsql) ");

    $groupBy = '';
    $select = 'SELECT ';
    if (count($this->_groupByColumns)) {
      $groupBy = " GROUP BY " . implode(', ', $this->_groupByColumns);
      $select .= implode(', ', $this->groupByColumns());
    }
    else {
      $select .= " *";
    }

    $where = 'WHERE (1)';
    if (!empty($this->_formValues['num_days_overdue'])) {
      $where .= ' AND days_overdue >= ' . $this->_formValues['num_days_overdue'];
    }

    if (!empty($this->_formValues['financial_type_id'])) {
      $value = $this->_formValues['financial_type_id'];
      $v = CRM_Financial_BAO_FinancialType::getAvailableFinancialTypes();
      foreach ($value as $key => $id) {
        $id = (int) $id;
        $value[$key] = $v[$id];
      }
      CRM_Core_DAO::executeQuery(sprintf("DELETE FROM temp_financialaging_customsearch WHERE ft_name NOT IN ('%s') ", implode("','", $value)));
    }

    if (!empty($this->_formValues['group_of_contact'])) {
      $values = implode(', ', (array) $this->_formValues['group_of_contact']);
      CRM_Core_DAO::executeQuery(sprintf("CREATE TEMPORARY TABLE temp_financialaging_groupcontacts DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci
       (SELECT DISTINCT contact_id
         FROM civicrm_group_contact
         WHERE group_id IN (%s) AND status = 'Added')
           UNION ALL
       (SELECT DISTINCT contact_id
         FROM civicrm_group_contact_cache
         WHERE group_id IN (%s)
       ) ", $values, $values));

      $where .= " AND contact_id IN (SELECT contact_id FROM temp_financialaging_groupcontacts) ";
    }

    if (!empty($this->_formValues['member_of_contact_id']) || !empty($this->_formValues['membership_type_id'])) {
      $whereClause = ' status_id != ' . array_search('Expired', CRM_Member_PseudoConstant::membershipStatus());
      foreach ([
       'member_of_contact_id' => ' AND membership_type_id IN ( SELECT id FROM civicrm_membership_type WHERE member_of_contact_id IN (%s) )',
       'membership_type_id' => ' AND membership_type_id IN ( %s ) ',
      ] as $filter => $searchString) {
        if (!empty($this->_formValues[$filter])) {
          $values = implode(', ', (array) $this->_formValues[$filter]);
          $whereClause .= sprintf($searchString, $values);
        }
      }
      CRM_Core_DAO::executeQuery(sprintf("CREATE TEMPORARY TABLE temp_financialaging_membershipcontacts DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci
       (SELECT DISTINCT contact_id
       FROM civicrm_membership m
       INNER JOIN civicrm_contact cc ON cc.id = m.contact_id AND cc.is_deleted = 0
       WHERE %s
      ) ", $whereClause));
      $where .= " AND contact_id IN (SELECT contact_id FROM temp_financialaging_membershipcontacts) ";
    }

    $this->_whereClause = $where;

    $sql = $select . $this->from() . $where . $groupBy;

    // -- this last line required to play nice with smart groups
    // INNER JOIN civicrm_contact contact_a ON contact_a.id = r.contact_id_a
    //for only contact ids ignore order.
    if (!$onlyIDs) {
      // Define ORDER BY for query in $sort, with default value
      if (!empty($sort)) {
        if (is_string($sort)) {
          $sql .= " ORDER BY $sort ";
        }
        else {
          $sql .= " ORDER BY " . trim($sort->orderBy());
        }
      }
    }

    if ($rowcount > 0 && $offset >= 0) {
      $sql .= " LIMIT $offset, $rowcount ";
    }

    return $sql;
  }

  public function groupByColumns($forSummary = FALSE) {
    $selectColumns = [
     'SUM(days_30) as days_30',
     'SUM(days_60) as days_60',
     'SUM(days_90) as days_90',
     'SUM(days_91_or_more) as days_91_or_more',
     'GROUP_CONCAT(DISTINCT currency) as currency',
     'SUM(paid) as paid',
     'SUM(total_amount) as total_amount',
     'SUM(days_overdue) as days_overdue',
     'SUM(num_records) as num_records',
    ];

    if (!$forSummary) {
      $selectColumns = array_merge($selectColumns, [
        'contact_id as contact_id',
        'sort_name as sort_name',
        'date_parm as date_parm',
        'GROUP_CONCAT(DISTINCT ft_name) as ft_name',
        'GROUP_CONCAT(DISTINCT ft_category) as ft_category',
        'GROUP_CONCAT(DISTINCT entity_type) as entity_type',
      ]);
    }

    return $selectColumns;
  }

  public function templateFile() {
    return 'CRM/FinancialAgingDup.tpl';
  }

  public function setDefaultValues() {
    return array();
  }

  public function alterRow(&$row) {
    if (empty($row['days_overdue'])) {
      $row['days_overdue'] = 0;
    }
  }

  public function summary() {
    $select = "SELECT " . implode(', ', $this->groupByColumns(TRUE));
    $sql = $select . $this->from() . $this->_whereClause . " GROUP BY currency ";

    $headers = [
     [
       ts('0-30 Days'),
       ts('31-60 Days'),
       ts('61-90 Days'),
       ts('91 or more Days'),
       ts('Currency'),
       ts('Paid'),
       ts('Total Amount'),
       ts('Days Overdue'),
       ts('Num. Records Combined'),
     ],
    ];

    return array_merge($headers, CRM_Core_DAO::executeQuery($sql)->fetchAll());
  }

  public function setTitle($title) {
    CRM_Utils_System::setTitle($title);
  }

  public function count() {
    $sql = $this->all();
    $dao = CRM_Core_DAO::executeQuery($sql,
           CRM_Core_DAO::$_nullArray);
    return $dao->N;
  }

  public function contactIDs($offset = 0, $rowcount = 0, $sort = NULL, $returnSQL = FALSE) {
    return $this->all($offset, $rowcount, $sort, FALSE, TRUE);
  }

  public function &columns() {
    return $this->_columns;
  }

  public function pledgePaymentSelectClause() {
    $end_date_parm = CRM_Utils_Date::processDate($this->_formValues['end_date'], NULL, FALSE, 'Y-m-d') ?: date('Y-m-d');
    $completeStatusID = CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_PledgePayment', 'status_id', 'Completed');

    return [
      'contact_id' => 'c.id',
      'date_parm' => "'$end_date_parm'",
      'exp_date' => 'DATE(li.scheduled_date)',
      'sort_name' => 'c.sort_name',
      'paid' => 'SUM(li.actual_amount)',
      'total_amount' => 'li.scheduled_amount',
      'currency' => 'p.currency',
      'line_id' => 'li.id',
      'ft_name' => 'ft.name',
      'ft_id' => 'ft.id',
      'ft_category' => "SUBSTRING(ft.name , 1, LOCATE( '---', ft.name) - 1)",
      'days_30' => " if((datediff( date('$end_date_parm') ,date(li.scheduled_date)) >= 0  AND datediff(date('$end_date_parm') ,date(li.scheduled_date)) <= 30) , li.scheduled_amount,  NULL)",
      'days_60' => " if((datediff( date('$end_date_parm') ,date(li.scheduled_date)) > 30  AND datediff(date('$end_date_parm') ,date(li.scheduled_date)) <= 60) , li.scheduled_amount,  NULL)",
      'days_90' => " if((datediff( date('$end_date_parm') ,date(li.scheduled_date)) > 60  AND datediff(date('$end_date_parm') ,date(li.scheduled_date)) <= 90) , li.scheduled_amount,  NULL)",
      'days_91_or_more' => "if(   (datediff( date('$end_date_parm') ,date(li.scheduled_date)) > 90)  , li.scheduled_amount,  NULL)",
      'num_records' => 'COUNT(li.id)',
      'days_overdue' => "DATEDIFF(DATE('$end_date_parm'), li.scheduled_date)",
      'entity_type' => "'pledge payment'",
    ];
  }

  public function recurringPaymentSelectClause() {
    $end_date_parm = CRM_Utils_Date::processDate($this->_formValues['end_date'], NULL, FALSE, 'Y-m-d') ?: date('Y-m-d');
    $pendingStatuses = implode(', ', [
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Overdue'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'),
    ]);

    return [
      'contact_id' => 'c.id',
      'date_parm' => "'$end_date_parm'",
      'exp_date' => 'DATE(temp.next_sched_contribution_date)',
      'sort_name' => 'c.sort_name',
      'paid' => '(SELECT COALESCE(SUM(total_amount),0.00) FROM civicrm_contribution WHERE contribution_status_id  = 1 AND contribution_recur_id = rr.id)',
      'total_amount' => 'cc.total_amount',
      'currency' => 'cc.currency',
      'line_id' => 'cc.id',
      'ft_name' => 'ft.name',
      'ft_id' => 'ft.id',
      'ft_category' => "SUBSTRING(ft.name , 1, LOCATE( '---', ft.name) - 1)",
      'days_30' => " if((datediff( date('$end_date_parm') ,date(temp.next_sched_contribution_date)) >= 0  AND datediff(date('$end_date_parm') ,date(temp.next_sched_contribution_date)) <= 30) , cc.total_amount,  NULL)",
      'days_60' => " if((datediff( date('$end_date_parm') ,date(temp.next_sched_contribution_date)) > 30  AND datediff(date('$end_date_parm') ,date(temp.next_sched_contribution_date)) <= 60) , cc.total_amount,  NULL)",
      'days_90' => " if((datediff( date('$end_date_parm') ,date(temp.next_sched_contribution_date)) > 60  AND datediff(date('$end_date_parm') ,date(temp.next_sched_contribution_date)) <= 90) , cc.total_amount,  NULL)",
      'days_91_or_more' => "if(   (datediff( date('$end_date_parm') ,date(temp.next_sched_contribution_date)) > 90)  , cc.total_amount,  NULL)",
      'num_records' => 'COUNT(li.id)',
      'days_overdue' => "DATEDIFF(
       DATE('$end_date_parm'),
       DATE(temp.next_sched_contribution_date)
      )",
      'entity_type' => "'recurring payment'",
    ];
  }

  public function pledgePaymentFromClause() {
    $endDate = 'NOW()';
    if (!empty($this->_formValues['end_date'])) {
      $endDate = sprintf("'%s'", date('Y-m-d', strtotime($this->_formValues['end_date'])));
    }
    return "
    FROM civicrm_pledge p
    INNER JOIN civicrm_pledge_payment li ON p.id = li.pledge_id  AND li.status_id NOT IN (1, 3) AND p.status_id NOT IN (1, 3) AND p.is_test = 0 AND DATE(li.scheduled_date) <= $endDate AND p.id IS NOT NULL
    LEFT JOIN civicrm_financial_type ft ON ft.id = p.financial_type_id
    LEFT JOIN civicrm_contact c ON c.id = p.contact_id AND c.is_deleted = 0 ";
  }

  public function recurringPaymentFromClause() {
    $endDate = 'NOW()';
    if (!empty($this->_formValues['end_date'])) {
      $endDate = sprintf("'%s'", date('Y-m-d', strtotime($this->_formValues['end_date'])));
    }
    $pendingStatuses = implode(', ', [
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Overdue'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'),
      CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed'),
    ]);
    return "
    FROM civicrm_contribution_recur rr
    INNER JOIN civicrm_contribution cc ON rr.id = cc.contribution_recur_id AND rr.contribution_status_id IN ($pendingStatuses)  AND DATE(rr.modified_date) = DATE(cc.receive_date)
    INNER JOIN temp_recur_next_date temp ON temp.id = rr.id AND temp.next_sched_contribution_date <= $endDate
    LEFT JOIN civicrm_line_item li ON cc.id = li.contribution_id
    LEFT JOIN civicrm_financial_type ft ON ft.id = li.financial_type_id
    LEFT JOIN civicrm_contact c ON c.id = cc.contact_id AND c.is_deleted = 0 ";
  }

  public static function getMembershipOrgs() {
    $org_ids = array();
    $sql = "SELECT distinct c.id, c.display_name
    FROM civicrm_membership_type mt
      LEFT JOIN civicrm_contact c on mt.member_of_contact_id = c.id
    WHERE is_active = 1
    ORDER BY c.display_name, mt.name
    ";

    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $org_ids[$dao->id] = $dao->display_name;
    }

    return $org_ids;
  }

}
