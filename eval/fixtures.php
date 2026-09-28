<?php
/**
 * Zeko AI engine — evaluation fixtures.
 *
 * Deterministic stand-ins for the live module data so the eval set measures the
 * engine's decisions (intent, source, which record wins) instead of whatever
 * happens to be in the database. These mirror the real integrations closely:
 * a source that substring-matches a term against title+excerpt the way the
 * module repositories do, and entity records scored by token recall the way
 * Zeko_AI_Integration::entity_match_score() does.
 *
 * The business fixtures keep "Zeko" in their names on purpose: that is the trap
 * that made a cafe answer "can I find a date on zeko?", and the eval set has to
 * keep standing on it.
 *
 * @package Zeko_AI
 */

return array(

	'knowledge' => array(
		array(
			'question' => 'What is Zeko?',
			'answer'   => 'Zeko is a community platform that brings jobs, courses, Q&A, shopping, freelancing, mentoring, dating, wallet and rewards together in one place. Create one account to use it all.',
			'module'   => '',
		),
		array(
			'question' => 'How do I earn rewards?',
			'answer'   => 'You earn points for participating across the ecosystem, then redeem them in the rewards catalog from your profile.',
			'module'   => 'rewards',
		),
		array(
			'question' => 'How does dating work?',
			'answer'   => 'On Zeko Dating you browse verified profiles, like and match, then schedule a date through the platform.',
			'module'   => 'love',
		),
		array(
			'question' => 'How do I change my email address?',
			'answer'   => 'Open your profile, choose Edit profile, then update the email field and confirm the new address from the link we send you.',
			'module'   => '',
		),
		array(
			'question' => 'How do course certificates work?',
			'answer'   => 'Course certificates show on your profile and can be shared or downloaded once you finish every lesson.',
			'module'   => 'learn',
		),
		array(
			'question' => 'How do I leave a course?',
			'answer'   => 'Open dating settings and choose Remove profile. Removing it takes you off the Zeko matching list.',
			'module'   => 'love',
		),
	),

	// Source type each corpus stands in for, matching what the module plugin
	// would register (zeko-jobs -> "job", zeko-learn -> "course"). The engine
	// uses it to keep a browse answer inside the module that was asked for, so
	// a fixture that claims the wrong type would hide a real defect.
	'corpus_types' => array(
		'business' => 'business',
		'courses'  => 'course',
		'jobs'     => 'job',
	),

	'corpus' => array(
		'business' => array(
			array(
				'id'      => 1,
				'title'   => 'Zeko Central Cafe & Bistro',
				'excerpt' => 'San Francisco, CA - cafes and restaurants serving breakfast and dinner.',
				'url'     => 'https://example.test/businesses/zeko-central-cafe-bistro/',
			),
			array(
				'id'      => 2,
				'title'   => 'Zeko Community Bank',
				'excerpt' => 'Accra - banking services, savings accounts and small business loans.',
				'url'     => 'https://example.test/businesses/zeko-community-bank/',
			),
			array(
				'id'      => 3,
				'title'   => 'Kente Barber Studio',
				'excerpt' => 'Accra - barbershop, hair cuts and beard trims.',
				'url'     => 'https://example.test/businesses/kente-barber-studio/',
			),
		),
		'courses'  => array(
			array(
				'id'      => 10,
				'title'   => 'WordPress for Beginners',
				'excerpt' => 'Intro course: build and launch your first site.',
				'url'     => 'https://example.test/learn/wordpress-for-beginners/',
			),
			array(
				'id'      => 11,
				'title'   => 'Advanced Freelance Pricing',
				'excerpt' => 'Course on setting rates, scoping and writing proposals.',
				'url'     => 'https://example.test/learn/advanced-freelance-pricing/',
			),
		),
		'jobs'     => array(
			array(
				'id'      => 20,
				'title'   => 'Frontend Developer',
				'excerpt' => 'Remote contract role building React and WordPress interfaces.',
				'url'     => 'https://example.test/jobs/frontend-developer/',
			),
		),
	),

	'entities' => array(
		'business' => array(
			array(
				'type'    => 'business',
				'label'   => 'Businesses',
				'id'      => 1,
				'title'   => 'Zeko Central Cafe & Bistro',
				'excerpt' => 'San Francisco, CA - cafes and restaurants serving breakfast and dinner.',
				'url'     => 'https://example.test/businesses/zeko-central-cafe-bistro/',
			),
			array(
				'type'    => 'business',
				'label'   => 'Businesses',
				'id'      => 2,
				'title'   => 'Zeko Community Bank',
				'excerpt' => 'Accra - banking services, savings accounts and small business loans.',
				'url'     => 'https://example.test/businesses/zeko-community-bank/',
			),
		),
	),
);
