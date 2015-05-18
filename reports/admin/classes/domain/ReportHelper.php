<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
class ReportHelper {
    public $report;
    public $outputType = 'web';
    public $startPage = 1;
    public $sortColumn = null;
    public $sortOrder = null;
    public $titleID = null;
    public $addWhere = array('','');
    public $hidden_inputs;
    public $paramDisplay = '';
    public $rprt_prm_add = '';
    public $showUnadjusted = false;
    public $maxRows;
    public $outlier;
    public $sumColsArray = array();
    public $groupColsArray = array();
    public $baseURL = null;
    public $perform_subtotal_flag;
    private $dropMonths = array();
    
    public function __construct() {
        if (isset($_REQUEST['reportID'])) {
            $this->report = new Report($_REQUEST['reportID']);
        } else {
            $this->report = new Report(header('Location: index.php'));
        }
        
        if (isset($_REQUEST['outputType'])) {
            $this->outputType = $_REQUEST['outputType'];
        }
        if ($this->outputType === 'web' && isset($_REQUEST['startPage'])) {
            $this->startPage = $_REQUEST['startPage'];
        }       
        if (isset($_REQUEST['sortColumn'])) {
            $this->sortColumn = $_REQUEST['sortColumn'];
        }
        if (isset($_REQUEST['sortOrder'])) {
            $this->sortOrder = $_REQUEST['sortOrder'];
        }

        $this->hidden_inputs = new HiddenInputs();
        $this->hidden_inputs->addReportID($this->report->getID());
        if (isset($_REQUEST['titleID']) && $_REQUEST['titleID']) {
            $this->titleID = $_REQUEST['titleID'];
            $this->paramDisplay = '<b>Title:</b> ' . $this->report->getUsageTitle($this->titleID) . '<br>';
            $this->rprt_prm_add = "&titleID=$this->titleID";
            $this->hidden_inputs->addTitleID($this->titleID);
        }

        $this->loopThroughParams();

        $this->sumColsArray = $this->report->getReportSums($this->dropMonths);
        $this->groupColsArray = $this->report->getGroupingColumns($this->dropMonths);

        $this->perform_subtotal_flag = count($this->groupColsArray)>0;
        $this->outlier = $this->report->getOutliers();
        Config::init();
        if (Config::$settings->baseURL) {
            if (strpos(Config::$settings->baseURL, '?') > 0) {
                $this->baseURL = Config::$settings->baseURL . '&';
            } else {
                $this->baseURL = Config::$settings->baseURL .'?';
            }
        }
    }

    public function numFields() {
        $flds = $this->reportTable->numFields();
        if($flds===null)
            die("reportTable->numFields() returned null!");
        return $flds;
    }
    
    public function process(DBResult &$reportArray, ReportNotes &$notes) {
        $countForGrouping = 0;
        $sumArray = array();
        $totalSumArray = array();
        $holdArray = array();

        $this->reportTable = new ReportTable($this->outputType, $reportArray->fetchFields());
        $this->reportTable->dropFields($this->dropMonths);
        
        if (! $this->reportTable->numFields()) {
            return;
        }

        $this->reportTable->printHeader($this->sortColumn, $this->sortOrder);
        echo '<tbody>';

        // loop through resultset
        $groupArrayCount = count($this->groupColsArray);
        $rowNum = 0;
        while ($currentRow = $reportArray->fetchRowPersist(MYSQLI_ASSOC) ) {
            if (isset($currentRow['platformID']))
                $notes->addPlatform($currentRow['platformID']);
            if (isset($currentRow['publisherPlatformID']))
                $notes->addPublisher($currentRow['publisherPlatformID']);

            $reset = ($rowNum+1 === $this->startPage);
            $performCheck = false;
            $print_subtotal_flag = false;
            $rowoutput = "<tr>";
            $colNum = 0;
            
            $currentRow_tmp = array();
            foreach ( $currentRow as $field => $data ) {
                // stop displaying columns once we hit title ID or platform ID
                if (($field === 'titleID') || ($field === 'platformID')) {
                    break;
                }
                if(in_array($field, $this->dropMonths)) {
                    continue;
                }
                $currentRow_tmp[$field] = $data;
            }
            $currentRow = $currentRow_tmp;

            foreach ( $currentRow as $field => $data ) {
                if ($data === '')
                    $data = '&nbsp;';

                // if sort is explicitly requested we will group on this column if it is allowed according to DB
                if (isset($holdArray[$colNum]) && isset($this->groupColsArray[$field]) && ($data != $holdArray[$colNum])) {  
                    if ($this->sortColumn === $colNum + 1) {
                        $hold_rprt_grpng_data = $holdArray[$colNum];
                        $print_subtotal_flag = true;
                        // if no sort order is specified, use default grouping
                    } else if (($this->sortOrder === '') && ($holdArray[$colNum] != '')) {
                        $this->groupColsArray[$field] = true;
                        // default echo flag to Y, we will reset later
                        $print_subtotal_flag = true;
                        $performCheck = true;
                        if ($groupArrayCount === 1)
                            $hold_rprt_grpng_data = $holdArray[$colNum];
                        else
                            $hold_rprt_grpng_data = 'Group';
                    }
                }
                if ($data != (isset($holdArray[$colNum]) ? $holdArray[$colNum] : null) 
                    || $reset 
                    || $this->outputType === 'xls' 
                    || ($this->perform_subtotal_flag && $this->sortOrder === '' && $groupArrayCount > 1)
                ) {
                    $reset = true;
                    $print_data = $data;
                } // echo it out if it's a number, needs to be printed regardless
                else if ($print_data == '0' . $print_data)
                    $print_data = '&nbsp;';
                else
                    $print_data = $data;

                if ($this->outputType === 'web' 
                    && ($print_data !== '&nbsp;') && $field === 'TITLE'
                ) {
                    if ($this->report->getID() != '1') {
                        $print_data .= '<br><font size="-4"><a target="_BLANK" href="report.php?reportID=1&prm_4=' . ($this->showUnadjusted ? 'Y' : 'N') . "&titleID={$currentRow['titleID']}&outputType=web'>view related titles</a></font>";
                    }
                    // echo link resolver link
                    if ((($currentRow['PRINT_ISSN']) || ($currentRow['ONLINE_ISSN'])) && isset($this->baseURL)) {
                        $print_data .= '<br><font size="-4"><a target="_BLANK" href="' . $this->getLinkResolverLink($currentRow) . '">view in link resolver</a></font>';
                    }
                }

                if (isset($currentRow[$field . '_OVERRIDE']) || (isset($currentRow[$field . '_OUTLIER']) && $currentRow[$field . '_OUTLIER'] > 0)) {
                    if (!$this->showUnadjusted && isset($currentRow[$field . '_OVERRIDE'])) {
                        $rowoutput .= "<td class='overriden'>" . $currentRow[$field . '_OVERRIDE'] . "</td>";
                    } else {
                        if ($this->showUnadjusted) {
                            if ($currentRow[$field . '_OUTLIER'] >= 4) {
                                $tmp_outlier_color = Color::$levelColors[$currentRow[$field . '_OUTLIER'] - 3];
                            } else {
                                $tmp_outlier_color = Color::$levelColors[$currentRow[$field . '_OUTLIER']];
                            }
                            $rowoutput .= "<td class='$tmp_outlier_color[1]'>$print_data</td>";
                            unset($tmp_outlier_color);
                        } else {
                            $rowoutput .= "<td class='flagged'>$print_data</td>";
                        }
                    }
                } else if (isset($currentRow['MERGE_IND'])) {
                    $rowoutput .= "<td class='merged'>$print_data</td>";
                } else {
                    $rowoutput .= "<td>$print_data</td>";
                }
                $holdArray[$colNum] = $data;

                if (isset($this->groupColsArray[$field])) {
                    if ($this->sortColumn === $colNum + 1) {
                        $hold_rprt_grpng_data = $holdArray[$colNum];
                    } else if ($this->sortOrder === '') {
                        if ($groupArrayCount === 1)
                            $hold_rprt_grpng_data = $holdArray[$colNum];
                        else
                            $hold_rprt_grpng_data = 'Group';
                    }
                }
                // get the numbers out for summing
                if (isset($sumArray[$field])) {
                    $sumArray[$field][] = $data;
                    $totalSumArray[$field] += $data; // filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) );
                } else {
                    $sumArray[$field] = array($data);
                    $totalSumArray[$field] = $data; // filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) );
                }
                // end if display columns is Y
                ++$colNum;
            } // end loop through columns
            ++$countForGrouping;

            // loop through the group arrays, if any are N then echo flag is N otherwise it will be left to Y
            // determine if the current row needs to be grouped
            if ($this->outputType != 'xls' && !($performCheck && in_array(false, $this->groupColsArray, true) !== false) && $print_subtotal_flag && $this->report->hasGroupTotalInd()) {
                $rowoutput .= "</tr>";
                if ($countForGrouping > 1) {
                    $rowparms = array("Total for $hold_rprt_grpng_data");
                    foreach ( $this->reportTable->fields() as $field ) {
                        $rowparms[] = $this->sumField($this->sumColsArray[$field], $sumArray[$field]);
                    }
                    $rowoutput .= $this->reportTable->prepare_row_as_str($rowparms);
                }
                $sumArray = array();
                $countForGrouping = 0;
                $rowoutput .= $this->reportTable->prepare_colspan_row('&nbsp;','') . "</tr>";
            }
            ++$rowNum;

            if ($this->maxRows <= 0  ||  $rowNum+1 < $this->maxRows)
                echo $rowoutput;
        }

        if ($this->outputType != 'xls' && $this->perform_subtotal_flag) {
            if ($this->report->hasGroupTotalInd() && $hold_rprt_grpng_data) {
                // one last grouping summary
                if ($countForGrouping > 1) {
                    $grp[] = array("Total for $hold_rprt_grpng_data");
                    foreach ( $this->reportTable->fields() as $field ) {
                        $grp[] = $this->sumField($this->sumColsArray[$field], $sumArray[$field]);
                    }
                    $this->reportTable->printRow($grp);
                }
                echo $this->reportTable->prep_colspan_row('&nbsp;','');
            }
            $rowparms = array();
            $total = '';
            foreach ($this->reportTable->fields() as $field) {
                if (isset($this->sumColsArray[$field]) && isset($totalSumArray[$field])) {
                    $total = $this->sumColTotal($this->sumColsArray[$field], $totalSumArray[$field], $rowNum);
                }
                if ($total) 
                    $rowparms[] = $total;
                else 
                    $rowparms[] = '&nbsp;';
            }
            $rowparms[0] = "Total for Report";
            $this->reportTable->printRow($rowparms);
        }

        if ($rowNum === 0) {
            echo $this->reportTable->prep_colspan_row('<i>Sorry, no rows were returned.</i>','');
        } else {
            echo "<tr><td colspan=" . $this->reportTable->numFields() . " align='right'><i>Showing rows {$this->startPage} to ";
            if (($this->maxRows > 0) && ($rowNum > $this->maxRows)) {
                echo "$this->maxRows of $this->maxRows";
            } else {
                echo "$rowNum of $rowNum";
            }

            echo '</i></td></tr>';
        }
        echo '</tbody>';
    }
    
    private function loopThroughParams() {
        foreach ( $this->report->getParameters() as $parm ) {
            $prm_value = $parm->getValue();
            if ($prm_value) {
                if ($parm->typeCode === 'chk') {
                    if (($prm_value === 'on') || ($prm_value === 'Y')) {
                        $this->showUnadjusted = true;
                        $this->hidden_inputs->addParam($parm->ID, 'Y');
                    }
                } else if ($parm->addWhereClause === 'limit') {
                    // decide what to do
                    $this->addWhere[0] = ''; // changed from $add_where. Assumed mistake.
                    $this->maxRows = $prm_value;
                } else {
                    if ($parm->addWhereNum == 2) {
                        $addWhereNum = 1;
                    } else {
                        $addWhereNum = 0;
                    }

                    $this->addWhere[$addWhereNum] .= " AND $parm->addWhereClause";

                    if ($parm->typeCode === 'dddr') {
                        if ($prm_value['from']['year'] === $prm_value['to']['year']) {
                            $months = array(
                                'JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC');
                            for ($i=0; $i<12; ++$i) {
                                if (! ($i>=$prm_value['from']['month']-1 && $i<=$prm_value['to']['month']-1)) {
                                    $this->dropMonths[] = $months[$i];
                                }
                            }
                        }
                        $this->hidden_inputs->addDateRange($parm->ID, $prm_value['from'], $prm_value['to']);
                    } else {
                        $prm_value = strtoupper($prm_value);
                        $this->addWhere[$addWhereNum] = preg_replace(
                            '/PARM/', $prm_value, $this->addWhere[$addWhereNum]
                        );
                        $this->hidden_inputs->addParam($parm->ID, $prm_value);
                    }
                }

                if ($parm->typeCode === 'chk') {
                    if ($prm_value === 'on' || $prm_value === 'Y') {
                        $this->paramDisplay .= '<b>Numbers are not adjusted for use violations</b><br>';
                    }
                } else if ($parm->addWhereClause === 'limit') {
                    $this->paramDisplay .= "<b>Limit:</b> Top $prm_value<br>";
                } else {
                    // if the parm comes through as an id (for publisher / platform or title), 
                    // display actual value for user friendliness 
                    if ($parm->displayPrompt === 'Provider / Publisher'
                        || $parm->displayPrompt === 'Provider' || $parm->displayPrompt === 'Publisher'
                    ) { 
                        $this->paramDisplay .= "<b>{$parm->displayPrompt}:</b> " 
                            . implode(', ', $parm->getPubPlatDisplayName($prm_value)) . '<br>';
                    } else {
                        if ($parm->typeCode === 'dddr') {
                            $prm_value = $prm_value['from']['month'] . '/' . $prm_value['from']['year'] . '-'
                                . $prm_value['to']['month'] . '/' . $prm_value['to']['year'];
                        }
                        //only display the param info at the top if it was entered
                        $this->paramDisplay .= "<b>{$parm->displayPrompt}:</b> '$prm_value'<br>"; 
                    }
                }
            }
        }
        
        // if titleID was passed in, add that to addwhere
        if (($this->report->getID() === '1') && ($this->titleID != '')) {
            $this->addWhere[1] .= " AND t.titleID = $this->titleID";
        }
    }
    
    public function getReportResults($isArchive) {
        if ($isArchive) {
            $orderBy = '';
        } else if ($this->sortColumn) {
            $orderBy = "ORDER BY $this->sortColumn $this->sortOrder";
        } else {
            $orderBy = $this->report->orderBySQL;
        }
        
        return $this->report->run($this->dropMonths,$isArchive, $this->addWhere, $orderBy);
    }
    
    public function getLinkResolverLink(&$thisRow) {
        if ($thisRow['PRINT_ISSN']) {
            if (($thisRow['ONLINE_ISSN'])) {
                return "{$this->baseURL}rft.issn={$thisRow['PRINT_ISSN']}&rft.eissn={$thisRow['ONLINE_ISSN']}";
            } else {
                return "{$this->baseURL}rft.issn={$thisRow['PRINT_ISSN']}";
            }
        } else {
            return "{$this->baseURL}rft.eissn={$thisRow['ONLINE_ISSN']}";
        }
    }
    
    public function sumField($sumType, array &$sumArray) {
        $total = '';
        if ($sumType === 'dollarsum') {
            $total = array_sum($sumArray);
            if ($total > 0)
                return money_format($total);
        } else if ($sumType === 'sum') {
            foreach ( $sumArray as $amt ) {
                if ($amt >= '0') {
                    $total += $amt;
                }
            }
            if ($total >= '0')
                return number_format($total);
        }
        return '&nbsp;';
    }
    
    private function sumColTotal($sumType, $totalSum, $rowcount) {
        if ($sumType === 'dollarsum') {
            return money_format($totalSum);
        } else if ($sumType === 'sum') {
            $total = number_format($totalSum);
            if (!$total)
                $total = '-';
            return $total;
        } else if ($sumType === 'avg') {
            return ($rowcount > 0) ? (number_format($totalSum / $rowcount) . '%') : '';
        }
        return '';
    }
}
