<?php

use TheJenos\SmartPiiRedactor\SmartPiiRedactor;

it('can test', function () {
    $smartPiiRedactor = App::make(SmartPiiRedactor::class);

    $text = <<<TEXT
        Napoleon Bonaparte[b] (born Napoleone di Buonaparte;[1][c] 15 August 1769 – 5 May 1821), later known by his regnal name Napoleon I, was Emperor of the French from 18 May 1804 until his first abdication in 1814, with a brief restoration during the Hundred Days in 1815. He rose to prominence as a general during the French Revolution and led a series of military campaigns across Europe and the Middle East during the French Revolutionary and Napoleonic Wars. As a statesman, he implemented numerous legal and administrative reforms in France and Europe.
        Born on the island of Corsica to a family of Italian origin, Napoleon moved to mainland France in 1779 and was commissioned as an officer in the French Royal Army in 1785. He supported the French Revolution in 1789 and promoted its cause in Corsica. He rose rapidly through the ranks after winning the siege of Toulon in 1793 and defeating royalist insurgents in Paris on 13 Vendémiaire in 1795. In 1796 he commanded a military campaign against the Austrians and their Italian allies in the War of the First Coalition, scoring decisive victories and becoming a national hero. He led an invasion of Egypt and Syria in 1798, which served as a springboard for his rise to political power. In November 1799 Napoleon engineered the Coup of 18 Brumaire against the French Directory and became First Consul of the Republic. He won the Battle of Marengo in 1800, which secured France's victory in the War of the Second Coalition, and in 1803, he sold the territory of Louisiana to the United States. In December 1804 Napoleon crowned himself Emperor of the French, further consolidating his power.
    TEXT;

    dd($smartPiiRedactor->maskWithMap($text));

    expect(true)->toBeTrue();
});
