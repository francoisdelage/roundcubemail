<?php

/*
 +-----------------------------------------------------------------------+
 | program/include/rcube_ldap.php                                        |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2006-2012, The Roundcube Dev Team                       |
 | Copyright (C) 2012, Kolab Systems AG                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Interface to an LDAP address directory                              |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 |         Andreas Dick <andudi (at) gmx (dot) ch>                       |
 |         Aleksander Machniak <machniak@kolabsys.com>                   |
 +-----------------------------------------------------------------------+
*/


/**
 * Model class to access an LDAP address directory
 *
 * @package Addressbook
 */
class rcube_ldap_addressbook extends rcube_addressbook
{
    /** public properties */
    public $primary_key = 'ID';
    public $groups = false;
    public $readonly = true;
    public $ready = false;
    public $group_id = 0;
    public $coltypes = array();

    /** private properties */
    protected $ldap;

    protected $conn;
    protected $prop = array();
    protected $fieldmap = array();
    protected $sub_filter;
    protected $filter = '';
    protected $result = null;
    protected $ldap_result = null;
    protected $mail_domain = '';
    protected $debug = false;

    private $base_dn = '';
    private $groups_base_dn = '';
    private $group_url = null;
    private $cache;


    /**
    * Object constructor
    *
    * @param array 	 $p            LDAP connection properties
    * @param boolean $debug        Enables debug mode
    * @param string  $mail_domain  Current user mail domain name
    */
    function __construct($p, $debug = false, $mail_domain = null)
    {
        $this->prop = $p;

        if (isset($p['searchonly']))
            $this->searchonly = $p['searchonly'];

        // See if the directory is writeable.
        if ($this->prop['writable'])
            $this->readonly = false;

        // parse and decode host names
        foreach ($this->prop['hosts'] as $k => $host)
            $this->prop['hosts'][$k] = rcube_utils::idn_to_ascii(rcube_utils::parse_host($host));

        // check if groups are configured
        if (is_array($p['groups']) && count($p['groups'])) {
            $this->groups = true;
            // set member field
            if (!empty($p['groups']['member_attr']))
                $this->prop['member_attr'] = strtolower($p['groups']['member_attr']);
            else if (empty($p['member_attr']))
                $this->prop['member_attr'] = 'member';
            // set default name attribute to cn
            if (empty($this->prop['groups']['name_attr']))
                $this->prop['groups']['name_attr'] = 'cn';
            if (empty($this->prop['groups']['scope']))
                $this->prop['groups']['scope'] = 'sub';
        }

        // fieldmap property is given
        if (is_array($p['fieldmap'])) {
            foreach ($p['fieldmap'] as $rf => $lf)
                $this->fieldmap[$rf] = $this->_attr_name(strtolower($lf));
        }
        else if (!empty($p)) {
            // read deprecated *_field properties to remain backwards compatible
            foreach ($p as $prop => $value)
                if (preg_match('/^(.+)_field$/', $prop, $matches))
                    $this->fieldmap[$matches[1]] = $this->_attr_name(strtolower($value));
        }

        // use fieldmap to advertise supported coltypes to the application
        foreach ($this->fieldmap as $colv => $lfv) {
            list($col, $type) = explode(':', $colv);
            list($lf, $limit, $delim) = explode(':', $lfv);

            if ($limit == '*') $limit = null;
            else               $limit = max(1, intval($limit));

            if (!is_array($this->coltypes[$col])) {
                $subtypes = $type ? array($type) : null;
                $this->coltypes[$col] = array('limit' => $limit, 'subtypes' => $subtypes);
            }
            elseif ($type) {
                $this->coltypes[$col]['subtypes'][] = $type;
                $this->coltypes[$col]['limit'] += $limit;
            }

            if ($delim)
               $this->coltypes[$col]['serialized'][$type] = $delim;

            if ($type && !$this->fieldmap[$col])
               $this->fieldmap[$col] = $lf;

            $this->fieldmap[$colv] = $lf;
        }

        // support for composite address
        if ($this->fieldmap['street'] && $this->fieldmap['locality']) {
            $this->coltypes['address'] = array(
               'limit'    => max(1, $this->coltypes['locality']['limit'] + $this->coltypes['address']['limit']),
               'subtypes' => array_merge((array)$this->coltypes['address']['subtypes'], (array)$this->coltypes['locality']['subtypes']),
               'childs' => array(),
               ) + (array)$this->coltypes['address'];

            foreach (array('street','locality','zipcode','region','country') as $childcol) {
                if ($this->fieldmap[$childcol]) {
                    $this->coltypes['address']['childs'][$childcol] = array('type' => 'text');
                    unset($this->coltypes[$childcol]);  // remove address child col from global coltypes list
                }
            }
        }
        else if ($this->coltypes['address']) {
            $this->coltypes['address'] += array('type' => 'textarea', 'childs' => null, 'size' => 40);

            // 'serialized' means the UI has to present a composite address field
            if ($this->coltypes['address']['serialized']) {
                $childprop = array('type' => 'text');
                $this->coltypes['address']['type'] = 'composite';
                $this->coltypes['address']['childs'] = array('street' => $childprop, 'locality' => $childprop, 'zipcode' => $childprop, 'country' => $childprop);
            }
        }

        // make sure 'required_fields' is an array
        if (!is_array($this->prop['required_fields'])) {
            $this->prop['required_fields'] = (array) $this->prop['required_fields'];
        }

        // make sure LDAP_rdn field is required
        if (!empty($this->prop['LDAP_rdn']) && !in_array($this->prop['LDAP_rdn'], $this->prop['required_fields'])) {
            $this->prop['required_fields'][] = $this->prop['LDAP_rdn'];
        }

        foreach ($this->prop['required_fields'] as $key => $val) {
            $this->prop['required_fields'][$key] = $this->_attr_name(strtolower($val));
        }

        // Build sub_fields filter
        if (!empty($this->prop['sub_fields']) && is_array($this->prop['sub_fields'])) {
            $this->sub_filter = '';
            foreach ($this->prop['sub_fields'] as $attr => $class) {
                if (!empty($class)) {
                    $class = is_array($class) ? array_pop($class) : $class;
                    $this->sub_filter .= '(objectClass=' . $class . ')';
                }
            }
            if (count($this->prop['sub_fields']) > 1) {
                $this->sub_filter = '(|' . $this->sub_filter . ')';
            }
        }

        $this->sort_col    = is_array($p['sort']) ? $p['sort'][0] : $p['sort'];
        $this->debug       = $debug;
        $this->mail_domain = $mail_domain;

        // initialize cache
        $rcube = rcube::get_instance();
        $this->cache = $rcube->get_cache('LDAP.' . asciiwords($this->prop['name']), 'db', 600);

        // initialize ldap wrapper
        $this->prop['attributes'] = array_values($this->fieldmap);
        $this->ldap = new rcube_ldap_generic($this->prop, true);

        // establish connection and bind on success
        if ($this->ldap->connect()) {
            $this->bind();
            $this->conn = $this->ldap->conn;
        }
    }


    /**
    * Bind connection according to config.
    * That may include current user's credentials and password
    */
    private function bind()
    {
        // connection failed, exit here
        if (!$this->ldap->connect())
            return;

        // go on with binding...
        $bind_pass = $this->prop['bind_pass'];
        $bind_user = $this->prop['bind_user'];
        $bind_dn   = $this->prop['bind_dn'];

        $this->base_dn        = $this->prop['base_dn'];
        $this->groups_base_dn = ($this->prop['groups']['base_dn']) ?
        $this->prop['groups']['base_dn'] : $this->base_dn;

        // User specific access, generate the proper values to use.
        if ($this->prop['user_specific']) {
            $rcube = rcube::get_instance();

            // No password set, use the session password
            if (empty($bind_pass)) {
                $bind_pass = $rcube->decrypt($_SESSION['password']);
            }

            // Get the pieces needed for variable replacement.
            if ($fu = $rcube->get_user_name())
                list($u, $d) = explode('@', $fu);
            else
                $d = $this->mail_domain;

            $dc = 'dc='.strtr($d, array('.' => ',dc=')); // hierarchal domain string

            $replaces = array('%dn' => '', '%dc' => $dc, '%d' => $d, '%fu' => $fu, '%u' => $u);

            if ($this->prop['search_base_dn'] && $this->prop['search_filter']) {
                if (!empty($this->prop['search_bind_dn']) && !empty($this->prop['search_bind_pw'])) {
                    $this->bind($this->prop['search_bind_dn'], $this->prop['search_bind_pw']);
                }

                // Search for the dn to use to authenticate
                $this->prop['search_base_dn'] = strtr($this->prop['search_base_dn'], $replaces);
                $this->prop['search_filter'] = strtr($this->prop['search_filter'], $replaces);

                $this->_debug("S: searching with base {$this->prop['search_base_dn']} for {$this->prop['search_filter']}");

                $res = @ldap_search($this->conn, $this->prop['search_base_dn'], $this->prop['search_filter'], array('uid'));
                if ($res) {
                    if (($entry = ldap_first_entry($this->conn, $res))
                        && ($bind_dn = ldap_get_dn($this->conn, $entry))
                    ) {
                        $this->_debug("S: search returned dn: $bind_dn");
                        $dn = ldap_explode_dn($bind_dn, 1);
                        $replaces['%dn'] = $dn[0];
                    }
                }
                else {
                    $this->_debug("S: ".ldap_error($this->conn));
                }

                // DN not found
                if (empty($replaces['%dn'])) {
                    if (!empty($this->prop['search_dn_default']))
                        $replaces['%dn'] = $this->prop['search_dn_default'];
                    else {
                        rcube::raise_error(array(
                            'code' => 100, 'type' => 'ldap',
                            'file' => __FILE__, 'line' => __LINE__,
                            'message' => "DN not found using LDAP search."), true);
                        return false;
                    }
                }
            }

            // Replace the bind_dn and base_dn variables.
            $bind_dn              = strtr($bind_dn, $replaces);
            $this->base_dn        = strtr($this->base_dn, $replaces);
            $this->groups_base_dn = strtr($this->groups_base_dn, $replaces);

            if (empty($bind_user)) {
                $bind_user = $u;
            }
        }

        if (empty($bind_pass)) {
            $this->ready = true;
        }
        else {
            if (!empty($bind_dn)) {
                $this->ready = $this->ldap->bind($bind_dn, $bind_pass);
            }
            else if (!empty($this->prop['auth_cid'])) {
                $this->ready = $this->ldap->sasl_bind($this->prop['auth_cid'], $bind_pass, $bind_user);
            }
            else {
                $this->ready = $this->ldap->sasl_bind($bind_user, $bind_pass);
            }
        }

        return $this->ready;
    }


    /**
     * Close connection to LDAP server
     */
    function close()
    {
        if ($this->ldap) {
            $this->ldap->close();
            $this->conn = null;
        }
    }


    /**
     * Returns address book name
     *
     * @return string Address book name
     */
    function get_name()
    {
        return $this->prop['name'];
    }


    /**
     * Set internal list page
     *
     * @param  number  Page number to list
     */
    function set_page($page)
    {
        $this->list_page = (int)$page;
        $this->ldap->set_page($this->list_page, $this->page_size);
    }

    /**
     * Set internal page size
     *
     * @param  number  Number of messages to display on one page
     */
    function set_pagesize($size)
    {
        $this->page_size = (int)$size;
        $this->ldap->set_page($this->list_page, $this->page_size);
    }


    /**
     * Set internal sort settings
     *
     * @param string $sort_col Sort column
     * @param string $sort_order Sort order
     */
    function set_sort_order($sort_col, $sort_order = null)
    {
        if ($this->fieldmap[$sort_col]) {
            $this->sort_col = $this->fieldmap[$sort_col];
        }
    }


    /**
     * Save a search string for future listings
     *
     * @param string $filter Filter string
     */
    function set_search_set($filter)
    {
        $this->filter = $filter;
    }


    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    function get_search_set()
    {
        return $this->filter;
    }


    /**
     * Activate/deactivate debug mode
     *
     * @param boolean $dbg True if LDAP commands should be logged
     * @access public
     */
    function set_debug($dbg = true)
    {
        $this->debug = $dbg;
        $this->ldap->set_debug($dbg);
    }


    /**
     * Reset all saved results and search parameters
     */
    function reset()
    {
        $this->result = null;
        $this->ldap_result = null;
        $this->filter = '';
    }


    /**
     * List the current set of contact records
     *
     * @param  array  List of cols to show
     * @param  int    Only return this number of records
     *
     * @return array  Indexed list of contact records, each a hash array
     */
    function list_records($cols=null, $subset=0)
    {
        if ($this->prop['searchonly'] && empty($this->filter) && !$this->group_id) {
            $this->result = new rcube_result_set(0);
            $this->result->searchonly = true;
            return $this->result;
        }

        // fetch group members recursively
        if ($this->group_id && $this->group_data['dn']) {
            $entries = $this->list_group_members($this->group_data['dn']);

            // make list of entries unique and sort it
            $seen = array();
            foreach ($entries as $i => $rec) {
                if ($seen[$rec['dn']]++)
                    unset($entries[$i]);
            }
            usort($entries, array($this, '_entry_sort_cmp'));

            $entries['count'] = count($entries);
            $this->result = new rcube_result_set($entries['count'], ($this->list_page-1) * $this->page_size);
        }
        else {
            // add general filter to query
            if (!empty($this->prop['filter']) && empty($this->filter))
                $this->set_search_set($this->prop['filter']);

            // exec LDAP search if no result resource is stored
            $this->ldap_result = $this->ldap->search($this->base_dn, $this->filter, $this->prop['scope']);

            // count contacts for this user
            $this->result = $this->count();

            // we have a search result resource
            if ($this->ldap_result && $this->result->count > 0) {
                // sorting still on the ldap server
                if ($this->sort_col && $this->prop['scope'] !== 'base' && !$this->ldap->vlv_active)
                    $this->ldap_result->sort($this->sort_col);

                // get all entries from the ldap server
                $entries = $this->ldap_result->entries();
            }
        }

        // start and end of the page
        $start_row = $this->ldap->vlv_active ? 0 : $this->result->first;
        $start_row = $subset < 0 ? $start_row + $this->page_size + $subset : $start_row;
        $last_row = $this->result->first + $this->page_size;
        $last_row = $subset != 0 ? $start_row + abs($subset) : $last_row;

        // filter entries for this page
        for ($i = $start_row; $i < min($entries['count'], $last_row); $i++)
            $this->result->add($this->_ldap2result($entries[$i]));

        return $this->result;
    }

    /**
     * Get all members of the given group
     *
     * @param string Group DN
     * @param array  Group entries (if called recursively)
     * @return array Accumulated group members
     */
    function list_group_members($dn, $count = false, $entries = null)
    {
        $group_members = array();

        // fetch group object
        if (empty($entries)) {
            $entries = $this->ldap->read_entries($dn, '(objectClass=*)', array('dn','objectClass','member','uniqueMember','memberURL'));
            if ($entries === false)
                return $group_members;
        }

        for ($i=0; $i < $entries['count']; $i++) {
            $entry = $entries[$i];

            if (empty($entry['objectclass']))
                continue;

            foreach ((array)$entry['objectclass'] as $objectclass) {
                switch (strtolower($objectclass)) {
                    case "group":
                    case "groupofnames":
                    case "kolabgroupofnames":
                        $group_members = array_merge($group_members, $this->_list_group_members($dn, $entry, 'member', $count));
                        break;
                    case "groupofuniquenames":
                    case "kolabgroupofuniquenames":
                        $group_members = array_merge($group_members, $this->_list_group_members($dn, $entry, 'uniquemember', $count));
                        break;
                    case "groupofurls":
                        $group_members = array_merge($group_members, $this->_list_group_memberurl($dn, $entry, $count));
                        break;
                }
            }

            if ($this->prop['sizelimit'] && count($group_members) > $this->prop['sizelimit'])
              break;
        }

        return array_filter($group_members);
    }

    /**
     * Fetch members of the given group entry from server
     *
     * @param string Group DN
     * @param array  Group entry
     * @param string Member attribute to use
     * @return array Accumulated group members
     */
    private function _list_group_members($dn, $entry, $attr, $count)
    {
        // Use the member attributes to return an array of member ldap objects
        // NOTE that the member attribute is supposed to contain a DN
        $group_members = array();
        if (empty($entry[$attr]))
            return $group_members;

        // read these attributes for all members
        $attrib = $count ? array('dn') : array_values($this->fieldmap);
        $attrib[] = 'objectClass';
        $attrib[] = 'member';
        $attrib[] = 'uniqueMember';
        $attrib[] = 'memberURL';

        for ($i=0; $i < $entry[$attr]['count']; $i++)
        {
            if (empty($entry[$attr][$i]))
                continue;

            $members = $this->ldap->read_entries($entry[$attr][$i], '(objectclass=*)', $attrib);
            if ($members == false) {
                $members = array();
            }

            // for nested groups, call recursively
            $nested_group_members = $this->list_group_members($entry[$attr][$i], $count, $members);

            unset($members['count']);
            $group_members = array_merge($group_members, array_filter($members), $nested_group_members);
        }

        return $group_members;
    }

    /**
     * List members of group class groupOfUrls
     *
     * @param string Group DN
     * @param array  Group entry
     * @param boolean True if only used for counting
     * @return array Accumulated group members
     */
    private function _list_group_memberurl($dn, $entry, $count)
    {
        $group_members = array();

        for ($i=0; $i < $entry['memberurl']['count']; $i++) {
            // extract components from url
            if (!preg_match('!ldap:///([^\?]+)\?\?(\w+)\?(.*)$!', $entry['memberurl'][$i], $m))
                continue;

            // add search filter if any
            $filter = $this->filter ? '(&(' . $m[3] . ')(' . $this->filter . '))' : $m[3];
            $func = rcube_ldap_generic::scope2func($m[2]);

            $attrib = $count ? array('dn') : array_values($this->fieldmap);
            if ($result = @$func($this->ldap->conn, $m[1], $filter,
                $attrib, 0, (int)$this->prop['sizelimit'], (int)$this->prop['timelimit'])
            ) {
                $this->_debug("S: ".ldap_count_entries($this->ldap->conn, $result)." record(s) for ".$m[1]);
            }
            else {
                $this->_debug("S: ".ldap_error($this->ldap->conn));
                return $group_members;
            }

            $entries = @ldap_get_entries($this->ldap->conn, $result);
            for ($j = 0; $j < $entries['count']; $j++)
            {
                if ($nested_group_members = $this->list_group_members($entries[$j]['dn'], $count))
                    $group_members = array_merge($group_members, $nested_group_members);
                else
                    $group_members[] = $entries[$j];
            }
        }

        return $group_members;
    }

    /**
     * Callback for sorting entries
     */
    function _entry_sort_cmp($a, $b)
    {
        return strcmp($a[$this->sort_col][0], $b[$this->sort_col][0]);
    }


    /**
     * Search contacts
     *
     * @param mixed   $fields   The field name of array of field names to search in
     * @param mixed   $value    Search value (or array of values when $fields is array)
     * @param int     $mode     Matching mode:
     *                          0 - partial (*abc*),
     *                          1 - strict (=),
     *                          2 - prefix (abc*)
     * @param boolean $select   True if results are requested, False if count only
     * @param boolean $nocount  (Not used)
     * @param array   $required List of fields that cannot be empty
     *
     * @return array  Indexed list of contact records and 'count' value
     */
    function search($fields, $value, $mode=0, $select=true, $nocount=false, $required=array())
    {
        $mode = intval($mode);

        // special treatment for ID-based search
        if ($fields == 'ID' || $fields == $this->primary_key) {
            $ids = !is_array($value) ? explode(',', $value) : $value;
            $result = new rcube_result_set();
            foreach ($ids as $id) {
                if ($rec = $this->get_record($id, true)) {
                    $result->add($rec);
                    $result->count++;
                }
            }
            return $result;
        }

        // use VLV pseudo-search for autocompletion
        $rcube = rcube::get_instance();

        if ($this->prop['vlv_search'] && $this->ready && join(',', (array)$fields) == join(',', $rcube->config->get('contactlist_fields'))) {
            // add general filter to query
            if (!empty($this->prop['filter']) && empty($this->filter))
                $this->set_search_set($this->prop['filter']);

            $entries = $this->ldap->search($this->base_dn, $this->filter ? $this->filter : '(objectclass=*)', $this->prop['scope'], false, $value);
            $this->result = new rcube_result_set(0);

            if ($entries === false) {
                return $this->result;
            }

            // get all entries of this page and post-filter those that really match the query
            $search = mb_strtolower($value);
            for ($i = 0; $i < $entries['count']; $i++) {
                $rec = $this->_ldap2result($entries[$i]);
                foreach ((array)$fields as $f) {
                    $val = mb_strtolower($rec[$f]);
                    switch ($mode) {
                    case 1:
                        $got = ($val == $search);
                        break;
                    case 2:
                        $got = ($search == substr($val, 0, strlen($search)));
                        break;
                    default:
                        $got = (strpos($val, $search) !== false);
                        break;
                    }

                    if ($got) {
                        $this->result->add($rec);
                        $this->result->count++;
                        break;
                    }
                }
            }

            return $this->result;
        }

        // use AND operator for advanced searches
        $filter = is_array($value) ? '(&' : '(|';
        // set wildcards
        $wp = $ws = '';
        if (!empty($this->prop['fuzzy_search']) && $mode != 1) {
            $ws = '*';
            if (!$mode) {
                $wp = '*';
            }
        }

        if ($fields == '*') {
            // search_fields are required for fulltext search
            if (empty($this->prop['search_fields'])) {
                $this->set_error(self::ERROR_SEARCH, 'nofulltextsearch');
                $this->result = new rcube_result_set();
                return $this->result;
            }
            if (is_array($this->prop['search_fields'])) {
                foreach ($this->prop['search_fields'] as $field) {
                    $filter .= "($field=$wp" . $this->_quote_string($value) . "$ws)";
                }
            }
        }
        else {
            foreach ((array)$fields as $idx => $field) {
                $val = is_array($value) ? $value[$idx] : $value;
                if ($f = $this->_map_field($field)) {
                    $filter .= "($f=$wp" . $this->_quote_string($val) . "$ws)";
                }
            }
        }
        $filter .= ')';

        // add required (non empty) fields filter
        $req_filter = '';
        foreach ((array)$required as $field) {
            if ($f = $this->_map_field($field))
                $req_filter .= "($f=*)";
        }

        if (!empty($req_filter))
            $filter = '(&' . $req_filter . $filter . ')';

        // avoid double-wildcard if $value is empty
        $filter = preg_replace('/\*+/', '*', $filter);

        // add general filter to query
        if (!empty($this->prop['filter']))
            $filter = '(&(' . preg_replace('/^\(|\)$/', '', $this->prop['filter']) . ')' . $filter . ')';

        // set filter string and execute search
        $this->set_search_set($filter);

        if ($select)
            $this->list_records();
        else
            $this->result = $this->count();

        return $this->result;
    }


    /**
     * Count number of available contacts in database
     *
     * @return object rcube_result_set Resultset with values for 'count' and 'first'
     */
    function count()
    {
        $count = 0;
        if ($this->ldap_result) {
            $count = $this->ldap_result->count();
        }
        else if ($this->group_id && $this->group_data['dn']) {
            $count = count($this->list_group_members($this->group_data['dn'], true));
        }
        else if ($this->ready) {
            // We have a connection but no result set, attempt to get one.
            if (empty($this->filter)) {
                // The filter is not set, set it.
                $this->filter = $this->prop['filter'];
            }

            $count = $this->ldap->search($this->base_dn, $this->filter, $this->prop['scope'], true);
        }

        return new rcube_result_set($count, ($this->list_page-1) * $this->page_size);
    }


    /**
     * Return the last result set
     *
     * @return object rcube_result_set Current resultset or NULL if nothing selected yet
     */
    function get_result()
    {
        return $this->result;
    }


    /**
     * Get a specific contact record
     *
     * @param mixed   Record identifier
     * @param boolean Return as associative array
     *
     * @return mixed  Hash array or rcube_result_set with all record fields
     */
    function get_record($id, $assoc=false)
    {
        $res = $this->result = null;

        if ($this->ready && $id) {
            $dn = self::dn_decode($id);

            if ($rec = $this->ldap->get_entry($dn)) {
                $rec = array_change_key_case($rec, CASE_LOWER);
            }

            // Use ldap_list to get subentries like country (c) attribute (#1488123)
            if (!empty($rec) && $this->sub_filter) {
                if ($entries = $this->ldap->list_entries($dn, $this->sub_filter, array_keys($this->prop['sub_fields']))) {
                    foreach ($entries as $entry) {
                        $lrec = array_change_key_case($entry, CASE_LOWER);
                        $rec  = array_merge($lrec, $rec);
                    }
                }
            }

            if (!empty($rec)) {
                // Add in the dn for the entry.
                $rec['dn'] = $dn;
                $res = $this->_ldap2result($rec);
                $this->result = new rcube_result_set(1);
                $this->result->add($res);
            }
        }

        return $assoc ? $res : $this->result;
    }


    /**
     * Check the given data before saving.
     * If input not valid, the message to display can be fetched using get_error()
     *
     * @param array Assoziative array with data to save
     * @param boolean Try to fix/complete record automatically
     * @return boolean True if input is valid, False if not.
     */
    public function validate(&$save_data, $autofix = false)
    {
        // validate e-mail addresses
        if (!parent::validate($save_data, $autofix)) {
            return false;
        }

        // check for name input
        if (empty($save_data['name'])) {
            $this->set_error(self::ERROR_VALIDATE, 'nonamewarning');
            return false;
        }

        // Verify that the required fields are set.
        $missing = null;
        $ldap_data = $this->_map_data($save_data);
        foreach ($this->prop['required_fields'] as $fld) {
            if (!isset($ldap_data[$fld]) || $ldap_data[$fld] === '') {
                $missing[$fld] = 1;
            }
        }

        if ($missing) {
            // try to complete record automatically
            if ($autofix) {
                $sn_field    = $this->fieldmap['surname'];
                $fn_field    = $this->fieldmap['firstname'];
                $mail_field  = $this->fieldmap['email'];

                // try to extract surname and firstname from displayname
                $reverse_map = array_flip($this->fieldmap);
                $name_parts  = preg_split('/[\s,.]+/', $save_data['name']);

                if ($sn_field && $missing[$sn_field]) {
                    $save_data['surname'] = array_pop($name_parts);
                    unset($missing[$sn_field]);
                }

                if ($fn_field && $missing[$fn_field]) {
                    $save_data['firstname'] = array_shift($name_parts);
                    unset($missing[$fn_field]);
                }

                // try to fix missing e-mail, very often on import
                // from vCard we have email:other only defined
                if ($mail_field && $missing[$mail_field]) {
                    $emails = $this->get_col_values('email', $save_data, true);
                    if (!empty($emails) && ($email = array_shift($emails))) {
                        $save_data['email'] = $email;
                        unset($missing[$mail_field]);
                    }
                }
            }

            // TODO: generate message saying which fields are missing
            if (!empty($missing)) {
                $this->set_error(self::ERROR_VALIDATE, 'formincomplete');
                return false;
            }
        }

        return true;
    }


    /**
     * Create a new contact record
     *
     * @param array    Hash array with save data
     *
     * @return encoded record ID on success, False on error
     */
    function insert($save_cols)
    {
        // Map out the column names to their LDAP ones to build the new entry.
        $newentry = $this->_map_data($save_cols);
        $newentry['objectClass'] = $this->prop['LDAP_Object_Classes'];

        // Verify that the required fields are set.
        $missing = null;
        foreach ($this->prop['required_fields'] as $fld) {
            if (!isset($newentry[$fld])) {
                $missing[] = $fld;
            }
        }

        // abort process if requiered fields are missing
        // TODO: generate message saying which fields are missing
        if ($missing) {
            $this->set_error(self::ERROR_VALIDATE, 'formincomplete');
            return false;
        }

        // Build the new entries DN.
        $dn = $this->prop['LDAP_rdn'].'='.$this->_quote_string($newentry[$this->prop['LDAP_rdn']], true).','.$this->base_dn;

        // Remove attributes that need to be added separately (child objects)
        $xfields = array();
        if (!empty($this->prop['sub_fields']) && is_array($this->prop['sub_fields'])) {
            foreach ($this->prop['sub_fields'] as $xf => $xclass) {
                if (!empty($newentry[$xf])) {
                    $xfields[$xf] = $newentry[$xf];
                    unset($newentry[$xf]);
                }
            }
        }

        if (!$this->ldap->add($dn, $newentry)) {
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
            return false;
        }

        foreach ($xfields as $xidx => $xf) {
            $xdn = $xidx.'='.$this->_quote_string($xf).','.$dn;
            $xf = array(
                $xidx => $xf,
                'objectClass' => (array) $this->prop['sub_fields'][$xidx],
            );

            $this->ldap->add($xdn, $xf);
        }

        $dn = self::dn_encode($dn);

        // add new contact to the selected group
        if ($this->group_id)
            $this->add_to_group($this->group_id, $dn);

        return $dn;
    }


    /**
     * Update a specific contact record
     *
     * @param mixed Record identifier
     * @param array Hash array with save data
     *
     * @return boolean True on success, False on error
     */
    function update($id, $save_cols)
    {
        $record = $this->get_record($id, true);

        $newdata     = array();
        $replacedata = array();
        $deletedata  = array();
        $subdata     = array();
        $subdeldata  = array();
        $subnewdata  = array();

        $ldap_data = $this->_map_data($save_cols);
        $old_data  = $record['_raw_attrib'];

        // special handling of photo col
        if ($photo_fld = $this->fieldmap['photo']) {
            // undefined means keep old photo
            if (!array_key_exists('photo', $save_cols)) {
                $ldap_data[$photo_fld] = $record['photo'];
            }
        }

        foreach ($this->fieldmap as $col => $fld) {
            if ($fld) {
                $val = $ldap_data[$fld];
                $old = $old_data[$fld];
                // remove empty array values
                if (is_array($val))
                    $val = array_filter($val);
                // $this->_map_data() result and _raw_attrib use different format
                // make sure comparing array with one element with a string works as expected
                if (is_array($old) && count($old) == 1 && !is_array($val)) {
                    $old = array_pop($old);
                }
                if (is_array($val) && count($val) == 1 && !is_array($old)) {
                    $val = array_pop($val);
                }
                // Subentries must be handled separately
                if (!empty($this->prop['sub_fields']) && isset($this->prop['sub_fields'][$fld])) {
                    if ($old != $val) {
                        if ($old !== null) {
                            $subdeldata[$fld] = $old;
                        }
                        if ($val) {
                            $subnewdata[$fld] = $val;
                        }
                    }
                    else if ($old !== null) {
                        $subdata[$fld] = $old;
                    }
                    continue;
                }

                // The field does exist compare it to the ldap record.
                if ($old != $val) {
                    // Changed, but find out how.
                    if ($old === null) {
                        // Field was not set prior, need to add it.
                        $newdata[$fld] = $val;
                    }
                    else if ($val == '') {
                        // Field supplied is empty, verify that it is not required.
                        if (!in_array($fld, $this->prop['required_fields'])) {
                            // ...It is not, safe to clear.
                            // #1488420: Workaround "ldap_mod_del(): Modify: Inappropriate matching in..."
                            // jpegPhoto attribute require an array() here. It looks to me that it works for other attribs too
                            $deletedata[$fld] = array();
                            //$deletedata[$fld] = $old_data[$fld];
                        }
                    }
                    else {
                        // The data was modified, save it out.
                        $replacedata[$fld] = $val;
                    }
                } // end if
            } // end if
        } // end foreach

        // console($old_data, $ldap_data, '----', $newdata, $replacedata, $deletedata, '----', $subdata, $subnewdata, $subdeldata);

        $dn = self::dn_decode($id);

        // Update the entry as required.
        if (!empty($deletedata)) {
            // Delete the fields.
            if (!$this->ldap->mod_del($dn, $deletedata)) {
                $this->set_error(self::ERROR_SAVING, 'errorsaving');
                return false;
            }
        } // end if

        if (!empty($replacedata)) {
            // Handle RDN change
            if ($replacedata[$this->prop['LDAP_rdn']]) {
                $newdn = $this->prop['LDAP_rdn'].'='
                    .$this->_quote_string($replacedata[$this->prop['LDAP_rdn']], true)
                    .','.$this->base_dn;
                if ($dn != $newdn) {
                    $newrdn = $this->prop['LDAP_rdn'].'='
                    .$this->_quote_string($replacedata[$this->prop['LDAP_rdn']], true);
                    unset($replacedata[$this->prop['LDAP_rdn']]);
                }
            }
            // Replace the fields.
            if (!empty($replacedata)) {
                if (!$this->ldap->mod_replace($dn, $replacedata)) {
                    $this->set_error(self::ERROR_SAVING, 'errorsaving');
                    return false;
                }
            }
        } // end if

        // RDN change, we need to remove all sub-entries
        if (!empty($newrdn)) {
            $subdeldata = array_merge($subdeldata, $subdata);
            $subnewdata = array_merge($subnewdata, $subdata);
        }

        // remove sub-entries
        if (!empty($subdeldata)) {
            foreach ($subdeldata as $fld => $val) {
                $subdn = $fld.'='.$this->_quote_string($val).','.$dn;
                if (!$this->ldap->delete($subdn)) {
                    return false;
                }
            }
        }

        if (!empty($newdata)) {
            // Add the fields.
            if (!$this->ldap->mod_add($dn, $newdata)) {
                $this->set_error(self::ERROR_SAVING, 'errorsaving');
                return false;
            }
        } // end if

        // Handle RDN change
        if (!empty($newrdn)) {
            if (!$this->ldap->rename($dn, $newrdn, null, true)) {
                $this->set_error(self::ERROR_SAVING, 'errorsaving');
                return false;
            }

            $dn    = self::dn_encode($dn);
            $newdn = self::dn_encode($newdn);

            // change the group membership of the contact
            if ($this->groups) {
                $group_ids = $this->get_record_groups($dn);
                foreach ($group_ids as $group_id)
                {
                    $this->remove_from_group($group_id, $dn);
                    $this->add_to_group($group_id, $newdn);
                }
            }

            $dn = self::dn_decode($newdn);
        }

        // add sub-entries
        if (!empty($subnewdata)) {
            foreach ($subnewdata as $fld => $val) {
                $subdn = $fld.'='.$this->_quote_string($val).','.$dn;
                $xf = array(
                    $fld => $val,
                    'objectClass' => (array) $this->prop['sub_fields'][$fld],
                );
                $this->ldap->add($subdn, $xf);
            }
        }

        return $newdn ? $newdn : true;
    }


    /**
     * Mark one or more contact records as deleted
     *
     * @param array   Record identifiers
     * @param boolean Remove record(s) irreversible (unsupported)
     *
     * @return boolean True on success, False on error
     */
    function delete($ids, $force=true)
    {
        if (!is_array($ids)) {
            // Not an array, break apart the encoded DNs.
            $ids = explode(',', $ids);
        } // end if

        foreach ($ids as $id) {
            $dn = self::dn_decode($id);

            // Need to delete all sub-entries first
            if ($this->sub_filter) {
                if ($entries = $this->ldap->list_entries($dn, $this->sub_filter)) {
                    foreach ($entries as $entry) {
                        if (!$this->ldap->delete($entry['dn'])) {
                            $this->set_error(self::ERROR_SAVING, 'errorsaving');
                            return false;
                        }
                    }
                }
            }

            // Delete the record.
            if (!$this->ldap->delete($dn)) {
                $this->set_error(self::ERROR_SAVING, 'errorsaving');
                return false;
            }

            // remove contact from all groups where he was member
            if ($this->groups) {
                $dn = self::dn_encode($dn);
                $group_ids = $this->get_record_groups($dn);
                foreach ($group_ids as $group_id) {
                    $this->remove_from_group($group_id, $dn);
                }
            }
        } // end foreach

        return count($ids);
    }


    /**
     * Remove all contact records
     */
    function delete_all()
    {
        // searching for contact entries
        $dn_list = $this->ldap->list_entries($this->base_dn, $this->prop['filter'] ? $this->prop['filter'] : '(objectclass=*)', array('dn'));

        if (!empty($dn_list)) {
            foreach ($dn_list as $idx => $entry) {
                $dn_list[$idx] = self::dn_encode($entry['dn']);
            }
            $this->delete($dn_list);
        }
    }


    /**
     * Converts LDAP entry into an array
     */
    private function _ldap2result($rec)
    {
        $out = array();

        if ($rec['dn'])
            $out[$this->primary_key] = self::dn_encode($rec['dn']);

        foreach ($this->fieldmap as $rf => $lf)
        {
            for ($i=0; $i < $rec[$lf]['count']; $i++) {
                if (!($value = $rec[$lf][$i]))
                    continue;

                list($col, $subtype) = explode(':', $rf);
                $out['_raw_attrib'][$lf][$i] = $value;

                if ($rf == 'email' && $this->mail_domain && !strpos($value, '@'))
                    $out[$rf][] = sprintf('%s@%s', $value, $this->mail_domain);
                else if (in_array($col, array('street','zipcode','locality','country','region')))
                    $out['address'.($subtype?':':'').$subtype][$i][$col] = $value;
                else if ($col == 'address' && strpos($value, '$') !== false)  // address data is represented as string separated with $
                    list($out[$rf][$i]['street'], $out[$rf][$i]['locality'], $out[$rf][$i]['zipcode'], $out[$rf][$i]['country']) = explode('$', $value);
                else if ($rec[$lf]['count'] > 1)
                    $out[$rf][] = $value;
                else
                    $out[$rf] = $value;
            }

            // Make sure name fields aren't arrays (#1488108)
            if (is_array($out[$rf]) && in_array($rf, array('name', 'surname', 'firstname', 'middlename', 'nickname'))) {
                $out[$rf] = $out['_raw_attrib'][$lf] = $out[$rf][0];
            }
        }

        return $out;
    }


    /**
     * Return real field name (from fields map)
     */
    private function _map_field($field)
    {
        return $this->fieldmap[$field];
    }


    /**
     * Convert a record data set into LDAP field attributes
     */
    private function _map_data($save_cols)
    {
        // flatten composite fields first
        foreach ($this->coltypes as $col => $colprop) {
            if (is_array($colprop['childs']) && ($values = $this->get_col_values($col, $save_cols, false))) {
                foreach ($values as $subtype => $childs) {
                    $subtype = $subtype ? ':'.$subtype : '';
                    foreach ($childs as $i => $child_values) {
                        foreach ((array)$child_values as $childcol => $value) {
                            $save_cols[$childcol.$subtype][$i] = $value;
                        }
                    }
                }
            }

            // if addresses are to be saved as serialized string, do so
            if (is_array($colprop['serialized'])) {
               foreach ($colprop['serialized'] as $subtype => $delim) {
                  $key = $col.':'.$subtype;
                  foreach ((array)$save_cols[$key] as $i => $val)
                     $save_cols[$key][$i] = join($delim, array($val['street'], $val['locality'], $val['zipcode'], $val['country']));
               }
            }
        }

        $ldap_data = array();
        foreach ($this->fieldmap as $col => $fld) {
            $val = $save_cols[$col];
            if (is_array($val))
                $val = array_filter($val);  // remove empty entries
            if ($fld && $val) {
                // The field does exist, add it to the entry.
                $ldap_data[$fld] = $val;
            }
        }

        return $ldap_data;
    }


    /**
     * Returns unified attribute name (resolving aliases)
     */
    private static function _attr_name($namev)
    {
        // list of known attribute aliases
        static $aliases = array(
            'gn' => 'givenname',
            'rfc822mailbox' => 'email',
            'userid' => 'uid',
            'emailaddress' => 'email',
            'pkcs9email' => 'email',
        );

        list($name, $limit) = explode(':', $namev, 2);
        $suffix = $limit ? ':'.$limit : '';

        return (isset($aliases[$name]) ? $aliases[$name] : $name) . $suffix;
    }


    /**
     * Prints debug info to the log
     */
    private function _debug($str)
    {
        if ($this->debug) {
            rcube::write_log('ldap', $str);
        }
    }


    /**
     * Quotes attribute value string
     *
     * @param string $str Attribute value
     * @param bool   $dn  True if the attribute is a DN
     *
     * @return string Quoted string
     */
    private static function _quote_string($str, $dn=false)
    {
        // take first entry if array given
        if (is_array($str))
            $str = reset($str);

        return $dn ? rcube_ldap_generic::escape_dn($str) : rcube_ldap_generic::escape_value($str);
    }


    /**
     * Setter for the current group
     * (empty, has to be re-implemented by extending class)
     */
    function set_group($group_id)
    {
        if ($group_id)
        {
            if (($group_cache = $this->cache->get('groups')) === null)
                $group_cache = $this->_fetch_groups();

            $this->group_id = $group_id;
            $this->group_data = $group_cache[$group_id];
        }
        else
        {
            $this->group_id = 0;
            $this->group_data = null;
        }
    }

    /**
     * List all active contact groups of this source
     *
     * @param string  Optional search string to match group name
     * @return array  Indexed list of contact groups, each a hash array
     */
    function list_groups($search = null)
    {
        if (!$this->groups)
            return array();

        // use cached list for searching
        $this->cache->expunge();
        if (!$search || ($group_cache = $this->cache->get('groups')) === null)
            $group_cache = $this->_fetch_groups();

        $groups = array();
        if ($search) {
            $search = mb_strtolower($search);
            foreach ($group_cache as $group) {
                if (strpos(mb_strtolower($group['name']), $search) !== false)
                    $groups[] = $group;
            }
        }
        else
            $groups = $group_cache;

        return array_values($groups);
    }

    /**
     * Fetch groups from server
     */
    private function _fetch_groups($vlv_page = 0)
    {
        $base_dn = $this->groups_base_dn;
        $filter = $this->prop['groups']['filter'];
        $name_attr = $this->prop['groups']['name_attr'];
        $email_attr = $this->prop['groups']['email_attr'] ? $this->prop['groups']['email_attr'] : 'mail';
        $sort_attrs = $this->prop['groups']['sort'] ? (array)$this->prop['groups']['sort'] : array($name_attr);
        $sort_attr = $sort_attrs[0];

        $this->_debug("C: Search [$filter][dn: $base_dn]");

        $ldap = $this->ldap;

        // use vlv to list groups
        if ($this->prop['groups']['vlv']) {
            $page_size = 200;
            if (!$this->prop['groups']['sort'])
                $this->prop['groups']['sort'] = $sort_attrs;

            $ldap = clone $this->ldap;
            $ldap->set_config($this->prop['groups']);
            $ldap->set_page($vlv_page+1, $page_size);
        }

        $ldap_data = $ldap->search($base_dn, $filter, array_unique(array('dn', 'objectClass', $name_attr, $email_attr, $sort_attr)));
        if ($ldap_data === false) {
            return array();
        }

        $groups = array();
        $group_sortnames = array();
        $group_count = $ldap_data["count"];
        for ($i=0; $i < $group_count; $i++)
        {
            $group_name = is_array($ldap_data[$i][$name_attr]) ? $ldap_data[$i][$name_attr][0] : $ldap_data[$i][$name_attr];
            $group_id = self::dn_encode($group_name);
            $groups[$group_id]['ID'] = $group_id;
            $groups[$group_id]['dn'] = $ldap_data[$i]['dn'];
            $groups[$group_id]['name'] = $group_name;
            $groups[$group_id]['member_attr'] = $this->get_group_member_attr($ldap_data[$i]['objectclass']);

            // list email attributes of a group
            for ($j=0; $ldap_data[$i][$email_attr] && $j < $ldap_data[$i][$email_attr]['count']; $j++) {
                if (strpos($ldap_data[$i][$email_attr][$j], '@') > 0)
                    $groups[$group_id]['email'][] = $ldap_data[$i][$email_attr][$j];
            }

            $group_sortnames[] = mb_strtolower($ldap_data[$i][$sort_attr][0]);
        }

        // recursive call can exit here
        if ($vlv_page > 0)
            return $groups;

        // call recursively until we have fetched all groups
        while ($this->prop['groups']['vlv'] && $group_count == $page_size) {
            $next_page = $this->_fetch_groups(++$vlv_page);
            $groups = array_merge($groups, $next_page);
            $group_count = count($next_page);
        }

        // when using VLV the list of groups is already sorted
        if (!$this->prop['groups']['vlv'])
            array_multisort($group_sortnames, SORT_ASC, SORT_STRING, $groups);

        // cache this
        $this->cache->set('groups', $groups);

        return $groups;
    }

    /**
     * Get group properties such as name and email address(es)
     *
     * @param string Group identifier
     * @return array Group properties as hash array
     */
    function get_group($group_id)
    {
        if (($group_cache = $this->cache->get('groups')) === null)
            $group_cache = $this->_fetch_groups();

        $group_data = $group_cache[$group_id];
        unset($group_data['dn'], $group_data['member_attr']);

        return $group_data;
    }

    /**
     * Create a contact group with the given name
     *
     * @param string The group name
     * @return mixed False on error, array with record props in success
     */
    function create_group($group_name)
    {
        $base_dn = $this->groups_base_dn;
        $new_dn = "cn=$group_name,$base_dn";
        $new_gid = self::dn_encode($group_name);
        $member_attr = $this->get_group_member_attr();
        $name_attr = $this->prop['groups']['name_attr'] ? $this->prop['groups']['name_attr'] : 'cn';

        $new_entry = array(
            'objectClass' => $this->prop['groups']['object_classes'],
            $name_attr => $group_name,
            $member_attr => '',
        );

        if (!$this->ldap->add($new_dn, $new_entry)) {
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
            return false;
        }

        $this->cache->remove('groups');

        return array('id' => $new_gid, 'name' => $group_name);
    }

    /**
     * Delete the given group and all linked group members
     *
     * @param string Group identifier
     * @return boolean True on success, false if no data was changed
     */
    function delete_group($group_id)
    {
        if (($group_cache = $this->cache->get('groups')) === null)
            $group_cache = $this->_fetch_groups();

        $base_dn = $this->groups_base_dn;
        $group_name = $group_cache[$group_id]['name'];
        $del_dn = "cn=$group_name,$base_dn";

        if (!$this->ldap->delete($del_dn)) {
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
            return false;
        }

        $this->cache->remove('groups');

        return true;
    }

    /**
     * Rename a specific contact group
     *
     * @param string Group identifier
     * @param string New name to set for this group
     * @param string New group identifier (if changed, otherwise don't set)
     * @return boolean New name on success, false if no data was changed
     */
    function rename_group($group_id, $new_name, &$new_gid)
    {
        if (($group_cache = $this->cache->get('groups')) === null)
            $group_cache = $this->_fetch_groups();

        $base_dn = $this->groups_base_dn;
        $group_name = $group_cache[$group_id]['name'];
        $old_dn = "cn=$group_name,$base_dn";
        $new_rdn = "cn=$new_name";
        $new_gid = self::dn_encode($new_name);

        if (!$this->ldap->rename($old_dn, $new_rdn, null, true)) {
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
            return false;
        }

        $this->cache->remove('groups');

        return $new_name;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be added
     * @return int    Number of contacts added
     */
    function add_to_group($group_id, $contact_ids)
    {
        if (($group_cache = $this->cache->get('groups')) === null)
            $group_cache = $this->_fetch_groups();

        if (!is_array($contact_ids))
            $contact_ids = explode(',', $contact_ids);

        $base_dn     = $this->groups_base_dn;
        $group_name  = $group_cache[$group_id]['name'];
        $member_attr = $group_cache[$group_id]['member_attr'];
        $group_dn    = "cn=$group_name,$base_dn";

        $new_attrs = array();
        foreach ($contact_ids as $id)
            $new_attrs[$member_attr][] = self::dn_decode($id);

        if (!$this->ldap->mod_add($group_dn, $new_attrs)) {
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
            return 0;
        }

        $this->cache->remove('groups');

        return count($new_attrs['member']);
    }

    /**
     * Remove the given contact records from a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be removed
     * @return int    Number of deleted group members
     */
    function remove_from_group($group_id, $contact_ids)
    {
        if (($group_cache = $this->cache->get('groups')) === null)
            $group_cache = $this->_fetch_groups();

        $base_dn     = $this->groups_base_dn;
        $group_name  = $group_cache[$group_id]['name'];
        $member_attr = $group_cache[$group_id]['member_attr'];
        $group_dn    = "cn=$group_name,$base_dn";

        $del_attrs = array();
        foreach (explode(",", $contact_ids) as $id)
            $del_attrs[$member_attr][] = self::dn_decode($id);

        if (!$this->ldap->mod_del($group_dn, $del_attrs)) {
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
            return 0;
        }

        $this->cache->remove('groups');

        return count($del_attrs['member']);
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     *
     * @return array List of assigned groups as ID=>Name pairs
     * @since 0.5-beta
     */
    function get_record_groups($contact_id)
    {
        if (!$this->groups)
            return array();

        $base_dn     = $this->groups_base_dn;
        $contact_dn  = self::dn_decode($contact_id);
        $name_attr   = $this->prop['groups']['name_attr'] ? $this->prop['groups']['name_attr'] : 'cn';
        $member_attr = $this->get_group_member_attr();
        $add_filter  = '';
        if ($member_attr != 'member' && $member_attr != 'uniqueMember')
            $add_filter = "($member_attr=$contact_dn)";
        $filter = strtr("(|(member=$contact_dn)(uniqueMember=$contact_dn)$add_filter)", array('\\' => '\\\\'));

        $this->_debug("C: Search [$filter][dn: $base_dn]");

        $res = @ldap_search($this->conn, $base_dn, $filter, array($name_attr));
        if ($res === false)
        {
            $this->_debug("S: ".ldap_error($this->conn));
            return array();
        }
        $ldap_data = ldap_get_entries($this->conn, $res);
        $this->_debug("S: ".ldap_count_entries($this->conn, $res)." record(s)");

        $groups = array();
        for ($i=0; $i<$ldap_data["count"]; $i++)
        {
            $group_name = $ldap_data[$i][$name_attr][0];
            $group_id = self::dn_encode($group_name);
            $groups[$group_id] = $group_id;
        }
        return $groups;
    }

    /**
     * Detects group member attribute name
     */
    private function get_group_member_attr($object_classes = array())
    {
        if (empty($object_classes)) {
            $object_classes = $this->prop['groups']['object_classes'];
        }
        if (!empty($object_classes)) {
            foreach ((array)$object_classes as $oc) {
                switch (strtolower($oc)) {
                    case 'group':
                    case 'groupofnames':
                    case 'kolabgroupofnames':
                        $member_attr = 'member';
                        break;

                    case 'groupofuniquenames':
                    case 'kolabgroupofuniquenames':
                        $member_attr = 'uniqueMember';
                        break;
                }
            }
        }

        if (!empty($member_attr)) {
            return $member_attr;
        }

        if (!empty($this->prop['groups']['member_attr'])) {
            return $this->prop['groups']['member_attr'];
        }

        return 'member';
    }


    /**
     * HTML-safe DN string encoding
     *
     * @param string $str DN string
     *
     * @return string Encoded HTML identifier string
     */
    static function dn_encode($str)
    {
        // @TODO: to make output string shorter we could probably
        //        remove dc=* items from it
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }

    /**
     * Decodes DN string encoded with _dn_encode()
     *
     * @param string $str Encoded HTML identifier string
     *
     * @return string DN string
     */
    static function dn_decode($str)
    {
        $str = str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($str);
    }

}