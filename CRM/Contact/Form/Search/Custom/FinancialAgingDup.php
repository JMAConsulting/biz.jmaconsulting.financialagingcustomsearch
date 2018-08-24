<?php

require_once 'CRM/Contact/Form/Search/Custom/Base.php';

class CRM_Contact_Form_Search_Custom_FinancialAgingDup extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;

  protected $_groupByColumns = [];

  function __construct(&$formValues) {
    parent::__construct($formValues);
    $this->_groupByColumns = array_keys(CRM_Utils_Array::value('group_bys', $formValues, []));
    $this->setColumns();
  }

  function __destruct() {
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_customsearch');
  }


  function buildForm(&$form) {
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
    $form->addDate('end_date', ts('Due By'), false, array('formatType' => 'custom'));

    $form->addSelect('financial_type_id', ['entity' => 'contribution', 'multiple' => 'multiple']);

    $form->add('text', 'num_days_overdue', ts('Number Days Overdue'));

    $form->addSelect('preferred_communication_method', ['entity' => 'contact',
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
    $form->addCheckBox("group_bys", ts('Group by columns'), $groupByElements, NULL,
      NULL, NULL, NULL, ['<br/>']);
  }

  function setColumns() {
    $this->_columns = [
      ts('Name') => 'sort_name',
      ts('0-30 Days') => 'days_30',
      ts('31-60 Days') => 'days_60',
      ts('61-90 Days') => 'days_90',
      ts('91 or more Days') => 'days_91_or_more',
      ts('Financial Type')=> 'ft_name',
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

  function select($paymentType, $onlyIDs) {
    $select = [];
    $end_date_parm = CRM_Utils_Date::processDate($this->_formValues['end_date'], NULL, FALSE, 'Y-m-d') ?: 'CURDATE()';

    if ($paymentType == 'Pledge payment') {
      $selectColumns = $this->pledgePaymentSelectClause();
    }
    else if ($paymentType == 'Recurring payment') {
      $selectColumns = $this->recurringPaymentSelectClause();
    }
    else {
      $selectColumns = $this->singleContributionSelectClause();
    }

    foreach ($selectColumns as $alias => $column) {
      $select[] = sprintf("%s as `%s`", $column, $alias);
    }

    return "SELECT " . implode(', ', $select);
  }

  public function from() {
    return "
    FROM temp_financialaging_customsearch temp
      LEFT JOIN civicrm_group_contact gc ON gc.contact_id = temp.contact_id AND gc.status = 'Added'
      LEFT JOiN civicrm_group_contact gcc ON gcc.contact_id = temp.contact_id
      LEFT JOIN civicrm_membership m ON m.contact_id = temp.contact_id
      LEFT JOIN civicrm_membership_type mt ON mt.id = m.membership_type_id
    ";
  }

  function where($includeContactIDs = FALSE) {
    $whereClauses = ['(1)'];
    foreach ([
      'end_date' => 'DATE(cc.receive_date) < \'%s\'',
      'num_days_overdue' => 'days_overdue = %s',
      'financial_type_id' => 'ft.id IN (%s)',
      'preferred_communication_method' => 'c.preferred_communication_method IN (%s)',
    ] as $filter => $dbColumn) {
      $value = CRM_Utils_Array::value($filter, $this->_formValues);
      if ($value) {
        $whereClauses[] = sprintf($dbColumn, implode(',', $value));
      }
      elseif ($filter == 'end_date') {
        $whereClauses[] = sprintf($dbColumn, date('Y-m-d'));
      }
    }
    return "WHERE " . implode(' AND ', $whereClauses);
  }

  function all($offset = 0, $rowcount = 0, $sort = null, $includeContactIDs = false, $onlyIDs = false) {
    CRM_Core_DAO::executeQuery('DROP TEMPORARY TABLE IF EXISTS temp_financialaging_customsearch');

    $select = $this->select('Pledge payment', $onlyIDs);
    $from = $this->pledgePaymentFromClause();
    $where = $this->where($includeContactIDs);
    $groupBy = ' GROUP BY li.id';
    $PPsql = $select . $from . $where . $groupBy;;

    $select = $this->select('Recurring payment', $onlyIDs);
    $from = $this->recurringPaymentFromClause();
    $RRsql = $select . $from . $where . $groupBy;

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
    foreach ([
      'group_of_contact' => '(gc.group_id IN (%s) OR gcc.group_id IN (%s))',
      'member_of_contact_id' => 'member_of_contact_id IN (%s)',
      'membership_type_id' => 'mt.id IN (%s)',
    ] as $filter => $searchString) {
      if (!empty($this->_formValues[$filter])) {
        $values = implode(', ', (array) $this->_formValues[$filter]);
        if ($filter == 'group_of_contact') {
          $where .= " AND " . sprintf($searchString, $values, $values);
        }
        else {
          $where .= " AND " . sprintf($searchString, $values);
        }
      }
    }

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

  function groupByColumns($forSummary = FALSE) {
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

  function templateFile() {
    return 'CRM/FinancialAgingDup.tpl';
  }

  function setDefaultValues() {
    return array();
  }

  function alterRow(&$row) {
    if (empty($row['days_overdue'])) {
      $row['days_overdue'] = 0;
    }
  }

  function summary() {
    $select = "SELECT " . implode(', ', $this->groupByColumns(TRUE));
    $sql = $select . $this->from() . " GROUP BY currency ";

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

  function setTitle($title) {
    CRM_Utils_System::setTitle($title);
  }

  function count() {
    $sql = $this->all();
    $dao = CRM_Core_DAO::executeQuery($sql,
            CRM_Core_DAO::$_nullArray);
    return $dao->N;
  }

  function contactIDs($offset = 0, $rowcount = 0, $sort = NULL, $returnSQL = false) {
    return $this->all($offset, $rowcount, $sort, false, true);
  }

  function &columns() {
    return $this->_columns;
  }

  public function pledgePaymentSelectClause() {
    $end_date_parm = CRM_Utils_Date::processDate($this->_formValues['end_date'], NULL, FALSE, 'Y-m-d') ?: date('Y-m-d');
    $pendingStatuses = implode(', ', [
      CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_PledgePayment', 'status_id', 'Overdue'),
      CRM_Core_PseudoConstant::getKey('CRM_Pledge_BAO_PledgePayment', 'status_id', 'Pending'),
    ]);

    return [
      'contact_id' => 'c.id',
      'date_parm' => "'$end_date_parm'",
      'exp_date' => 'DATE(cc.receive_date)',
      'sort_name' => 'c.sort_name',
      'paid' => 'SUM(pp1.actual_amount)',
      'total_amount' => 'p.amount',
      'currency' => 'cc.currency',
      'line_id' => 'li.id',
      'ft_name' => 'ft.name',
      'ft_category' => "SUBSTRING(ft.name , 1, LOCATE( '---', ft.name) - 1)",
      'days_30' => "(SELECT SUM(pp2.scheduled_amount)
          FROM civicrm_pledge_payment pp2
          WHERE pp2.pledge_id = pp1.pledge_id AND
           pp2.status_id IN ($pendingStatuses) AND
           DATE(pp2.scheduled_date) BETWEEN DATE(cc.receive_date) AND DATE_ADD(DATE(cc.receive_date), INTERVAL 30 DAY)
      )",
      'days_60' => "(SELECT SUM(pp3.scheduled_amount)
            FROM civicrm_pledge_payment pp3
            WHERE pp3.pledge_id = pp1.pledge_id AND
             pp3.status_id IN ($pendingStatuses) AND
              DATE(pp3.scheduled_date) BETWEEN DATE_ADD(DATE(cc.receive_date), INTERVAL 31 DAY) AND DATE_ADD(DATE(cc.receive_date), INTERVAL 60 DAY)
      )",
      'days_90' => "(SELECT SUM(pp4.scheduled_amount)
            FROM civicrm_pledge_payment pp4
            WHERE pp4.pledge_id = pp1.pledge_id AND
             pp4.status_id IN ($pendingStatuses) AND
              DATE(pp4.scheduled_date) BETWEEN DATE_ADD(DATE(cc.receive_date), INTERVAL 61 DAY) AND DATE_ADD(DATE(cc.receive_date), INTERVAL 90 DAY)
      )",
      'days_91_or_more' => "(SELECT SUM(pp5.scheduled_amount)
            FROM civicrm_pledge_payment pp5
            WHERE pp5.pledge_id = pp1.pledge_id AND
            pp5.status_id IN ($pendingStatuses) AND
            DATE(pp5.scheduled_date) >= DATE_ADD(DATE(cc.receive_date), INTERVAL 91 DAY)
      )",
      'num_records' => 'COUNT(li.id)',
      'days_overdue' => "DATEDIFF(
        (SELECT MAX(DATE(scheduled_date)) FROM civicrm_pledge_payment WHERE status_id IN ($pendingStatuses) AND pledge_id = p.id),
        (SELECT MIN(DATE(scheduled_date)) FROM civicrm_pledge_payment WHERE status_id IN ($pendingStatuses) AND pledge_id = p.id)
      )",
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
      'exp_date' => 'DATE(cc.receive_date)',
      'sort_name' => 'c.sort_name',
      'paid' => 'li.line_total',
      'total_amount' => 'cc.total_amount',
      'currency' => 'cc.currency',
      'line_id' => 'li.id',
      'ft_name' => 'ft.name',
      'ft_category' => "SUBSTRING(ft.name , 1, LOCATE( '---', ft.name) - 1)",
      'days_30' => "(SELECT SUM(rr2.amount)
          FROM civicrm_contribution_recur rr2
          WHERE rr2.id = rr1.id AND
           rr2.contribution_status_id IN ($pendingStatuses) AND
           DATE(rr2.start_date) BETWEEN DATE(cc.receive_date) AND DATE_ADD(DATE(cc.receive_date), INTERVAL 30 DAY)
      )",
      'days_60' => "(SELECT SUM(rr3.amount)
            FROM civicrm_contribution_recur rr3
            WHERE rr3.id = rr1.id AND
             rr3.contribution_status_id IN ($pendingStatuses) AND
              DATE(rr3.start_date) BETWEEN DATE_ADD(DATE(cc.receive_date), INTERVAL 31 DAY) AND DATE_ADD(DATE(cc.receive_date), INTERVAL 60 DAY)
      )",
      'days_90' => "(SELECT SUM(rr4.amount)
            FROM civicrm_contribution_recur rr4
            WHERE rr4.id = rr1.id AND
             rr4.contribution_status_id IN ($pendingStatuses) AND
              DATE(rr4.start_date) BETWEEN DATE_ADD(DATE(cc.receive_date), INTERVAL 61 DAY) AND DATE_ADD(DATE(cc.receive_date), INTERVAL 90 DAY)
      )",
      'days_91_or_more' => "(SELECT SUM(rr5.amount)
            FROM civicrm_contribution_recur rr5
            WHERE rr5.id = rr1.id AND
            rr5.contribution_status_id IN ($pendingStatuses) AND
            DATE(rr5.start_date) >= DATE_ADD(DATE(cc.receive_date), INTERVAL 91 DAY)
      )",
      'num_records' => 'COUNT(li.id)',
      'days_overdue' => "DATEDIFF(
        (SELECT MAX(DATE(start_date)) FROM civicrm_contribution_recur WHERE contribution_status_id IN ($pendingStatuses) AND id = rr1.id),
        (SELECT MIN(DATE(start_date)) FROM civicrm_contribution_recur WHERE contribution_status_id IN ($pendingStatuses) AND id = rr1.id)
      )",
      'entity_type' => "'recurring payment'",
    ];
  }

  public function pledgePaymentFromClause() {
    return "
    FROM civicrm_line_item li
      INNER JOIN civicrm_contribution cc ON cc.id = li.contribution_id
      INNER JOIN civicrm_pledge_payment pp1 ON pp1.contribution_id = cc.id AND pp1.contribution_id = li.contribution_id AND pp1.contribution_id IS NOT NULL
      INNER JOIN civicrm_pledge p ON p.id = pp1.pledge_id
      LEFT JOIN civicrm_financial_type ft ON ft.id = li.financial_type_id
      LEFT JOIN civicrm_contact c ON c.id = cc.contact_id
    ";
  }

  public function recurringPaymentFromClause() {
    return "
    FROM civicrm_line_item li
      INNER JOIN civicrm_contribution cc ON cc.id = li.contribution_id
      INNER JOIN civicrm_contribution_recur rr1 ON rr1.id = cc.contribution_recur_id
      LEFT JOIN civicrm_financial_type ft ON ft.id = li.financial_type_id
      LEFT JOIN civicrm_contact c ON c.id = cc.contact_id
    ";
  }

  public function singleContributionFromClause() {
    return "
    FROM civicrm_line_item li
      INNER JOIN civicrm_contribution cc ON cc.id = li.contribution_id AND cc.contribution_recur_id IS NULL AND cc.is_test = 0
      INNER JOIN civicrm_entity_financial_trxn eft ON eft.entity_id = cc.id AND eft.entity_table = 'civicrm_contribution'
      INNER JOIN civicrm_financial_trxn cft ON cft.id = eft.financial_trxn_id
      LEFT JOIN civicrm_financial_type ft ON ft.id = li.financial_type_id
      LEFT JOIN civicrm_contact c ON c.id = cc.contact_id
    ";
  }

  public function singleContributionSelectClause() {
    $end_date_parm = CRM_Utils_Date::processDate($this->_formValues['end_date'], NULL, FALSE, 'Y-m-d') ?: date('Y-m-d');
    $completeStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    return [
      'contact_id' => 'c.id',
      'date_parm' => "'$end_date_parm'",
      'sort_name' => 'c.sort_name',
      'paid' => "(SELECT SUM(total_amount) FROM civicrm_financial_trxn cft1 INNER JOIN civicrm_entity_financial_trxn eft1 ON cft1.id = eft1.financial_trxn_id AND eft1.entity_table = 'civicrm_contribution' AND cft1.status_id = $completeStatus WHERE eft1.entity_id = cc.id)",
      'total_amount' => 'cc.total_amount',
      'currency' => 'cc.currency',
      'line_id' => 'li.id',
      'ft_name' => 'ft.name',
      'ft_category' => "SUBSTRING(ft.name , 1, LOCATE( '---', ft.name) - 1)",
      'days_30' => "(cc.total_amount - (SELECT SUM(total_amount) FROM civicrm_financial_trxn cft1 INNER JOIN civicrm_entity_financial_trxn eft1 ON cft1.id = eft1.financial_trxn_id AND eft1.entity_table = 'civicrm_contribution' AND cft1.status_id = $completeStatus WHERE eft1.entity_id = cc.id))",
      'days_60' => "(cc.total_amount )",
      'days_90' => "(cc.total_amount )",
      'days_91_or_more' => "(cc.total_amount)",
      'num_records' => 'COUNT(li.id)',
      'days_overdue' => "'N/A'",
      'entity_type' => "'single contribution'",
    ];
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
    while($dao->fetch()) {
      $org_ids[$dao->id] = $dao->display_name;
    }

		return $org_ids;
	}

}
