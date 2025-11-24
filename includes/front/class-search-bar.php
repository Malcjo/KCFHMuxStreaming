<?php

namespace KCFH\Streaming\Front;

if(!defined('ABSPATH')) exit;

class Search_Bar{
    /*
        Render the Searchbar form
    */

    public static function render(): string{
        ob_start();
        ?>
        <form class="kcfh-search" id="kcfhSearchForm" autocomplete="off">
            <input
                type="search"
                id="kcfhSearchInput"
                name="kcfh_q"
                placeholder="Search by name"
            />
            <button type="submit">Search</button>
            <button type="button" id="kcfhSearchClear">Clear</button>
        </form>
        <?php
        return ob_get_clean();
    }
}