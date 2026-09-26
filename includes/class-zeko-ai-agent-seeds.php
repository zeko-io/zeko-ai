<?php
/**
 * Starter knowledge for the Community Agent.
 *
 * A compact FAQ across the whole ecosystem so a fresh install has a
 * functional agent out of the box. Rows are inserted with source 'seed'
 * (distinguishable from admin/auto-learned entries) and are skipped when an
 * exact question already exists, so seeding is idempotent and safe to rerun.
 *
 * @package Zeko_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_AI_Agent_Seeds. */
class Zeko_AI_Agent_Seeds {

	/**
	 * SEED VERSION.
	 *
	 * @var mixed
	 */
	const SEED_VERSION = 5;

	/**
	 * Default starter knowledge. Filterable via 'zeko_ai_agent_seed_knowledge'.
	 *
	 * @return array<int,array{question:string,answer:string,module?:string,weight?:float}>
	 */
	public static function get(): array {
		$seeds = array(
			array(
				'module'   => 'general',
				'question' => 'What is Zeko?',
				'answer'   => 'Zeko is a community platform that brings jobs, courses, Q&A, shopping, freelancing, mentoring, dating, wallet and rewards together in one place. Create one account to use it all.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I create an account?',
				'answer'   => 'Click Sign Up on the top-right of any page, enter your email and password, and confirm your email. You can then fill in your profile and start using the ecosystem.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I verify my profile?',
				'answer'   => 'Go to your profile settings and submit the requested verification details. Once an admin reviews and approves them, you will get a verified badge on your profile.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I report a problem?',
				'answer'   => 'Use the report button on any post, job, product or profile. You can also contact the site admin directly. Reports are reviewed by a human.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I find a job?',
				'answer'   => 'Open the Jobs section and search by keyword or location, or use AI Search to see everything at once. Filter by type, save searches for alerts, and apply directly from each job card.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I post a job?',
				'answer'   => 'Go to Jobs and choose Post a job. Add a title, description, location and type, then publish. Your listing appears in search and recommendations immediately.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How can I get paid on Zeko?',
				'answer'   => 'Payments flow through Zeko Pay. Add funds to your wallet, send payments to freelancers or employers, and withdraw when you have a balance. Every transaction is tracked in your wallet tab.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I take a course?',
				'answer'   => 'Browse the Learn library, open a course and start its lessons. Work through quizzes and earn certificates and rewards as you complete milestones.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I get a certificate?',
				'answer'   => 'Complete every lesson and pass the course quiz. Once done, your certificate appears in your profile and you can share it.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I ask a question?',
				'answer'   => 'Open Q&A, write a clear question, add relevant tags, and post it. Good questions and answers earn reputation and badges.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I answer a question?',
				'answer'   => 'Open any question and write a helpful answer. If other members find it useful they will upvote it, which grows your reputation.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I buy a product?',
				'answer'   => 'Open the Shop, add items to your cart, and check out with Zeko Pay. You can track orders from your dashboard.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I sell on Zeko?',
				'answer'   => 'Open the Shop and choose to add a product. Fill in a title, description, price and images, then publish. Sales go through Zeko Pay.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I hire a freelancer?',
				'answer'   => 'Post a project brief in Freelance, review bids from freelancers, and award the project. Milestones and escrow keep payments safe.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I get hired as a freelancer?',
				'answer'   => 'Complete your portfolio, then browse projects and send a proposal with your rate. Winning projects are paid through milestone escrow.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How do I find a mentor?',
				'answer'   => 'Use Mentor to browse mentors and filter by topic. Book a session, set goals together, and log your progress after each meeting.',
			),
			array(
				'module'   => 'love',
				'question' => 'How does dating work?',
				'answer'   => 'Build an honest profile, browse verified profiles, like profiles that interest you, and start a chat once you match. You can then schedule a date through the platform.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'What is Zeko Pay?',
				'answer'   => 'Zeko Pay is the unified wallet for the ecosystem. You can add funds, pay for shop items and freelance milestones, send money, and withdraw your balance.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I add money to my wallet?',
				'answer'   => 'Open your wallet and choose Add funds. Pick a payment method, confirm the amount, and your balance updates right away.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I withdraw my earnings?',
				'answer'   => 'Open your wallet, choose Withdraw, pick a payout method and confirm. Withdrawals are processed by the platform and appear in your transaction history.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'How do I earn rewards?',
				'answer'   => 'Participate across the ecosystem: take courses, ask good questions, land jobs, and complete projects. Each activity earns points and badges.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'How do I redeem my points?',
				'answer'   => 'Open the Rewards catalog and choose a reward you can afford. Redeemed rewards are delivered through the platform.',
			),
			array(
				'module'   => 'moderation',
				'question' => 'How does content moderation work?',
				'answer'   => 'Zeko AI reviews community content for harmful, abusive or spammy material. Anything flagged lands in a moderation queue where a human confirms the final decision.',
			),
			array(
				'module'   => 'moderation',
				'question' => 'What happens to flagged content?',
				'answer'   => 'Flagged content is hidden pending review. A moderator approves, dismisses, or removes it. You can appeal a decision by contacting the admin.',
			),
			array(
				'module'   => 'search',
				'question' => 'How do I search everything at once?',
				'answer'   => 'Use AI Search to look across jobs, courses, questions, products, freelance projects and mentors from one box. Results are ranked by relevance.',
			),
			array(
				'module'   => 'recommend',
				'question' => 'How do recommendations work?',
				'answer'   => 'AI Recommendations look at your activity across the ecosystem and surface jobs, courses, questions, projects and products you are likely to enjoy.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I talk to the AI assistant?',
				'answer'   => 'Open the assistant chat from the floating button on any page, the AI tab in your dashboard, or the AI Assistant page. Ask about any part of the ecosystem and I will point you in the right direction.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I reset my password?',
				'answer'   => 'Use the Forgot password link on the login form. We will email you a reset link that lets you set a new password.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I change my email address?',
				'answer'   => 'Open your account settings, choose Email, and enter the new address. We will send a confirmation link to the new email before the change takes effect.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I delete my account?',
				'answer'   => 'Open account settings and choose Delete account. You will be asked to confirm, and your data is removed or anonymized according to the privacy policy.',
			),
			array(
				'module'   => 'general',
				'question' => 'Is Zeko free to use?',
				'answer'   => 'Joining Zeko and using the core features is free. Some premium courses, promoted listings, and paid services are priced individually and paid through Zeko Pay.',
			),
			array(
				'module'   => 'general',
				'question' => 'What can the AI assistant do?',
				'answer'   => 'The assistant answers questions about any part of the ecosystem, searches across modules, offers follow-up questions, and links you to the right page. It learns from feedback so answers improve over time.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I contact support?',
				'answer'   => 'Use the report button or visit the contact page. The support team reviews every message, usually within one business day.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I change my password?',
				'answer'   => 'Open account settings and choose Password. Enter your current password and a new one, then save. Use a strong password you do not reuse elsewhere.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I update my profile photo?',
				'answer'   => 'Go to your profile, choose Edit, and upload a new photo. It appears across your posts, jobs and other activity.',
			),
			array(
				'module'   => 'general',
				'question' => 'What is a verified profile?',
				'answer'   => 'A verified profile has confirmed identity details reviewed by an admin and shows a verified badge. Verification increases trust for dating, freelance and job interactions.',
			),
			array(
				'module'   => 'general',
				'question' => 'Can I use Zeko on my phone?',
				'answer'   => 'Yes. The site is fully responsive and works from any mobile browser. There is no separate app required.',
			),
			array(
				'module'   => 'general',
				'question' => 'What are community guidelines?',
				'answer'   => 'Guidelines keep the platform safe and respectful: no harassment, hate, spam, scams or harmful content. Violations may lead to warnings, content removal, or account suspension.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do notifications work?',
				'answer'   => 'You can choose which activities send notifications: new matches, job alerts, replies to your questions, project bids, and wallet transactions. Manage them in notification settings.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I get a verified badge?',
				'answer'   => 'Submit identity verification from your profile settings. Once an admin reviews and approves your details, the verified badge appears automatically.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I change my username?',
				'answer'   => 'Usernames are set when you create your profile and can be changed once from account settings. Your profile link updates to the new name.',
			),
			array(
				'module'   => 'general',
				'question' => 'What is the Zeko radar digest?',
				'answer'   => 'The weekly radar email summarizes your freshest AI recommendations based on recent activity, so you never miss a relevant job, course, question, project or product.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I stop the radar digest emails?',
				'answer'   => 'Open your email preferences and turn off the weekly radar digest. Existing settings take effect from the next scheduled send.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I apply for a job?',
				'answer'   => 'Open the job listing and choose Apply. Upload your resume or portfolio, add a short cover note, and submit. Employers respond through the platform.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I save a job search?',
				'answer'   => 'Run a search in Jobs, then choose Save search. You will receive alerts when new listings match your criteria.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I set up job alerts?',
				'answer'   => 'Save a search or choose alert settings for your preferred keywords and locations. Matching new jobs are emailed to you.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'What job types are supported?',
				'answer'   => 'Zeko Jobs supports full-time, part-time, contract, internship, freelance and remote roles. Filter by type in the Jobs section.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I manage my applications?',
				'answer'   => 'Open Applications from your dashboard to see the status of every application, edit your resume, or withdraw before an employer reviews it.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I edit a job posting?',
				'answer'   => 'Open your posted jobs from the dashboard, choose the listing, and select Edit. Changes go live immediately.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I close a job posting?',
				'answer'   => 'Open the listing in your dashboard and choose Close or Mark as filled. The listing stops appearing in search.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'What makes a good job description?',
				'answer'   => 'Use a clear title, list responsibilities and requirements, mention the location and type, and describe your team or culture. Specific listings get more qualified applicants.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I review applicants?',
				'answer'   => 'Open your posted job and view Applicants. You can shortlist, message, and schedule interviews from the platform.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'Can employers pay through Zeko?',
				'answer'   => 'Yes. Employers can pay contractors and freelancers through Zeko Pay with milestone escrow and transaction history.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I recommend a candidate for a job?',
				'answer'   => 'Use the referral option on the job listing to share it with a friend. Some listings offer referral rewards.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I enroll in a course?',
				'answer'   => 'Open the course page and choose Enroll. Free courses start immediately; paid courses are unlocked after payment through Zeko Pay.',
			),
			array(
				'module'   => 'learn',
				'question' => 'What is a lesson quiz?',
				'answer'   => 'Each lesson may end with a short quiz to test what you learned. Pass it to progress to the next lesson and earn progress rewards.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I track my course progress?',
				'answer'   => 'Your dashboard shows progress per course: lessons completed, quizzes passed, and remaining steps. Pick up where you left off anytime.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I earn rewards while learning?',
				'answer'   => 'Complete lessons, pass quizzes and finish courses to earn points and badges. Milestones are credited automatically.',
			),
			array(
				'module'   => 'learn',
				'question' => 'Are certificates recognized?',
				'answer'   => 'Course certificates show on your profile and can be shared or downloaded. They confirm completion of the course content on Zeko.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I publish a course?',
				'answer'   => 'Open Learn and choose Create course. Add lessons, quizzes and a title, then submit for review. Published courses appear in the library.',
			),
			array(
				'module'   => 'learn',
				'question' => 'Can I download course videos?',
				'answer'   => 'Course content streams in your browser. Downloads are not supported for most courses to protect the authors.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I become a course instructor?',
				'answer'   => 'Create a course from the Learn section. High-quality, well-reviewed courses can become featured in recommendations.',
			),
			array(
				'module'   => 'learn',
				'question' => 'What happens when I pass a quiz?',
				'answer'   => 'Passing a quiz unlocks the next lesson and credits points to your rewards balance. Failing lets you retry after a short cooldown.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I find courses on a topic?',
				'answer'   => 'Use the Learn search or AI Search to find courses by keyword. Filter by level, language and price.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do tags work on Q&A?',
				'answer'   => 'Tags group questions by topic so the right experts find them. Add up to five relevant tags when asking.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I earn reputation?',
				'answer'   => 'Post good questions, provide helpful answers, and receive upvotes. High reputation unlocks community privileges like editing and moderation flags.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I mark an answer as accepted?',
				'answer'   => 'If you asked the question, choose Accept next to the answer that solved your problem. The author earns reputation and the question is marked resolved.',
			),
			array(
				'module'   => 'qa',
				'question' => 'Can I edit my question?',
				'answer'   => 'Yes, from your question page. Edit to clarify the topic or add details. Edited questions appear with a history for transparency.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I upvote an answer?',
				'answer'   => 'Use the upvote arrow under an answer when you find it useful. Upvotes raise the answer and reward its author.',
			),
			array(
				'module'   => 'qa',
				'question' => 'What are topic followers?',
				'answer'   => 'Following a topic subscribes you to new questions in it. Unfollow anytime from the topic page.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I report a bad answer?',
				'answer'   => 'Use the report option under the answer. It goes to moderators for review, and the AI assistant learns from feedback to improve future answers.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I add a product to the shop?',
				'answer'   => 'Open the Shop and choose Add product. Add a title, description, price and images, then publish after review.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I track my orders?',
				'answer'   => 'Open Orders from your dashboard. Each order shows status, payment, and delivery details.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do refunds work?',
				'answer'   => 'Contact the seller or support within the refund window for damaged or incorrect items. Approved refunds return to your Zeko wallet.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I leave product reviews?',
				'answer'   => 'After receiving an order, open it and choose Review. Rate the product and add a short comment.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I become a seller?',
				'answer'   => 'Open the Shop and choose Sell. Complete seller verification and add your first product to go live.',
			),
			array(
				'module'   => 'shop',
				'question' => 'What payment methods are accepted?',
				'answer'   => 'Checkout uses Zeko Pay. Add funds with a supported card or bank method, then pay for items in seconds.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do shipping costs work?',
				'answer'   => 'Shipping is set by the seller per product or order and shown at checkout before you pay.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'What is milestone escrow?',
				'answer'   => 'Funds for a project are held in escrow and released to the freelancer as milestones are approved, protecting both sides.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I set a project budget?',
				'answer'   => 'In the project brief, add a budget range or fixed price. Freelancers bid within your range.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I compare freelancer bids?',
				'answer'   => 'Open your project and view bids side by side: price, timeline, portfolio and rating. Shortlist the best fits.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I get paid for a project?',
				'answer'   => 'When a milestone is approved, escrow releases payment to your Zeko wallet. Withdraw anytime once the balance clears.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I build my freelance profile?',
				'answer'   => 'Add a portfolio of past work, your skills, hourly rate and availability. Complete profiles win more projects.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'What happens if a project needs changes?',
				'answer'   => 'Request changes through the project thread. Both sides can agree on a change order that updates scope and payment before work continues.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I dispute a project?',
				'answer'   => 'Open the project and choose Dispute. Support reviews the conversation and escrow milestones to reach a fair outcome.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How do I book a session?',
				'answer'   => 'Open a mentor profile and choose a time from their calendar. Sessions are confirmed by the mentor.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How do I become a mentor?',
				'answer'   => 'Apply from the Mentor section with your expertise and availability. Approved mentors appear in mentor search.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'What happens in a mentoring session?',
				'answer'   => 'You set goals with your mentor, meet on the scheduled call, and review progress after each session.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How do I set mentoring goals?',
				'answer'   => 'After booking, add goals to the session. Your mentor tailors advice to help you reach them.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How do I rate a mentor?',
				'answer'   => 'After a session, leave a rating and review on the mentor profile. Ratings help others choose.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I verify my dating profile?',
				'answer'   => 'Submit a quick identity check from your dating settings. Verified members appear higher in matches.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I get more matches?',
				'answer'   => 'Complete your bio, add recent photos, and be active. Recommendations learn your preferences from your likes.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I report a member?',
				'answer'   => 'Open the profile and choose Report. Moderators review it, and you can block the member from contacting you.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I block someone?',
				'answer'   => 'Open their profile and choose Block. They can no longer see your profile or message you.',
			),
			array(
				'module'   => 'love',
				'question' => 'Can I change my dating preferences?',
				'answer'   => 'Yes. Open dating settings and adjust who you see and who can see you. Matches update to your new preferences.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I send money to a member?',
				'answer'   => 'Open your wallet and choose Send. Enter the recipient and amount, confirm, and the funds move between wallets instantly.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'What is my account number?',
				'answer'   => 'Your Zeko account number is shown in your wallet tab. Use it to receive payments from employers and clients.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I view my transactions?',
				'answer'   => 'Open your wallet and choose Transactions. Every payment in and out is listed with date, amount and status.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'Are there wallet fees?',
				'answer'   => 'Adding funds and paying members is free. Withdrawal and some payout methods may carry a small fee shown before you confirm.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How long do withdrawals take?',
				'answer'   => 'Withdrawals are processed by the platform, usually within one to three business days depending on the payout method.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I secure my wallet?',
				'answer'   => 'Use a strong password, keep your account number private, and never share payment details. Report suspicious activity immediately.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'What rewards can I earn?',
				'answer'   => 'Points, badges and redeemable items. Earn them by learning, helping others, completing projects and being active.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'How do I check my points balance?',
				'answer'   => 'Open the Rewards section from your dashboard. Your balance and recent activity are listed there.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'Do points expire?',
				'answer'   => 'Points follow the reward policy, which states whether points expire and how long they stay valid. Check the Rewards page for the current policy.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'What are badges?',
				'answer'   => 'Badges celebrate milestones like finishing your first course or winning your first project. They display on your profile.',
			),
			array(
				'module'   => 'moderation',
				'question' => 'How do I appeal a moderation decision?',
				'answer'   => 'Contact the admin with details about the decision and why you disagree. Appeals are reviewed by a human moderator.',
			),
			array(
				'module'   => 'moderation',
				'question' => 'What counts as spam?',
				'answer'   => 'Repeated promotional content, unsolicited links, or mass-messaging members without their consent. Spam is removed automatically where possible.',
			),
			array(
				'module'   => 'moderation',
				'question' => 'How do I hide a post I flagged?',
				'answer'   => 'Flagged content is hidden pending review. A moderator then approves, dismisses, or removes it.',
			),
			array(
				'module'   => 'search',
				'question' => 'What can AI Search find?',
				'answer'   => 'AI Search looks across jobs, courses, questions, products, freelance projects and mentors at once, ranked by relevance to your query.',
			),
			array(
				'module'   => 'search',
				'question' => 'Why does the AI remember my interests?',
				'answer'   => 'The assistant keeps a private per-member memory of facts you share, like your interests or goals, and uses them to personalize search and recommendations. You can clear it anytime.',
			),
			array(
				'module'   => 'search',
				'question' => 'How do I clear my AI memory?',
				'answer'   => 'Open your AI settings and choose Clear memory. Your stored facts are removed and personalization resets.',
			),
			array(
				'module'   => 'recommend',
				'question' => 'Why am I seeing these recommendations?',
				'answer'   => 'Recommendations are built from your recent activity: what you viewed, searched, liked and completed across the ecosystem.',
			),
			array(
				'module'   => 'recommend',
				'question' => 'How do I refresh my recommendations?',
				'answer'   => 'Open the recommendations page and choose Refresh. New activity is folded into the next batch.',
			),
			array(
				'module'   => 'recommend',
				'question' => 'How do I hide a recommendation?',
				'answer'   => 'Choose Not interested on the item. The engine learns from that and adjusts future suggestions.',
			),
			array(
				'module'   => 'business',
				'question' => 'What is the Business Directory?',
				'answer'   => 'The Business Directory lists companies and stores around you with reviews, ratings and verified badges. Every business has its own page, and several offer bookable services right from their listing.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I find a business?',
				'answer'   => 'Open the directory page and search by name or city, or type a question in the assistant and I will look it up for you. Listings are ranked with sponsored businesses first, then featured, then rating and recency. Each result links to the business page at /businesses/slug/.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I add my business?',
				'answer'   => 'Go to the Add your business page (the public submit form at /add-business/), fill in your name, category, description, contact details, location and opening hours, then submit. Your listing goes live after review and is managed from My businesses.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I create a business account?',
				'answer'   => 'A business account is a Zeko member account with a business listing attached. First sign up and confirm your email, then open the Add your business page at /add-business/ and submit your business details — name, category, description, contact info, location and opening hours. After review your listing is live at /businesses/slug/, and you manage it from the business portal at /business-portal/.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I claim my business?',
				'answer'   => 'Open the business page and choose Claim. After you confirm you are the owner, the listing shows as claimed and you get a verified badge once an admin checks the details.',
			),
			array(
				'module'   => 'business',
				'question' => 'What is a verified business?',
				'answer'   => 'A verified business has confirmed identity details reviewed by the platform and shows a verified badge. Verified listings build trust with customers and rank higher in the directory.',
			),
			array(
				'module'   => 'business',
				'question' => 'What does featured or sponsored mean?',
				'answer'   => 'Sponsored and featured businesses appear at the top of the directory: sponsored first, then featured, then regular listings sorted by rating and recency. They are highlighted so visitors see them first.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I book a service?',
				'answer'   => 'Open the business page, review its services, and choose one you want. Pick an available time slot and confirm the booking. You can manage your bookings from the business dashboard.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I review a business?',
				'answer'   => 'After visiting a business, open its page and leave a rating with a short review. Your rating is averaged into the business score shown in the directory.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I follow a business?',
				'answer'   => 'Open the business page and choose Follow. You will see its updates in your feed and notifications. The follower count is shown on the business profile.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I find business opening hours?',
				'answer'   => 'Every business page shows the owner\'s opening hours for the week. For example, the demo café Zeko Central Cafe & Bistro lists its weekly hours right on its page. Owners update hours from their business portal.',
			),
			array(
				'module'   => 'business',
				'question' => 'Where is a business page located?',
				'answer'   => 'Each business has a clean single page at /businesses/slug/ — for example /businesses/zeko-central-cafe-bistro/ for the demo café. Its services live at /businesses/slug/services/id/.',
			),
			array(
				'module'   => 'business',
				'question' => 'What is the business portal?',
				'answer'   => 'The business portal at /business-portal/ is the owner dashboard. From it you manage your profile, services, opening hours, reviews, bookings and business pages.',
			),
			array(
				'module'   => 'business',
				'question' => 'Why does the directory page look different?',
				'answer'   => 'The directory, blog-grid and blog-list pages use a clean full-width layout with no sidebars so listings and posts are easier to scan. Classic blog layouts still show the sidebar when you prefer it.',
			),
			array(
				'module'   => 'general',
				'question' => 'Where can I read Zeko blog posts?',
				'answer'   => 'The blog section uses four page templates: Grid (no sidebar), List (no sidebar), Classic with a right sidebar, and Classic with a left sidebar. Pick whichever layout suits how you like to browse.',
			),
			array(
				'module'   => 'general',
				'question' => 'Why don\'t some pages have sidebars?',
				'answer'   => 'The directory, blog grid, blog list and shop pages render full-width content without sidebars for a cleaner, faster overview. Classic blog layouts keep the sidebar when you want it.',
			),
			array(
				'module'   => 'general',
				'question' => 'How are pages linked together on Zeko?',
				'answer'   => 'Key pages are the business directory (/business-directory/), the add-your-business form (/add-business/), the owner portal (/business-portal/), plus the blog, shop, jobs, learn and other module pages. Business pages follow /businesses/slug/, and their services live at /businesses/slug/services/id/.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I edit my business listing?',
				'answer'   => 'Open My businesses from the business portal at /business-portal/ and choose Edit on your listing. Update your name, category, description, contact details or location, then save. Changes go live after an admin review.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I update my business opening hours?',
				'answer'   => 'From the business portal choose Opening hours and set your hours for each day of the week, then save. The new hours appear on your business page right away.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I add a service to my business?',
				'answer'   => 'Open your business and choose Add service. Give it a name, price or rate, and a duration if it is bookable. It appears at /businesses/slug/services/id/ and in the booking flow.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I manage my business bookings?',
				'answer'   => 'Your business dashboard lists every booking request. From there you can confirm, reschedule or cancel appointments, and your customer is notified automatically.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I remove my business from the directory?',
				'answer'   => 'Open My businesses from the business portal and choose Remove on the listing. Confirm the removal and the public page at /businesses/slug/ is taken down.',
			),
			array(
				'module'   => 'business',
				'question' => 'What does a business page show?',
				'answer'   => 'Every business page at /businesses/slug/ shows the description, category, contact details, opening hours, services and member reviews. Claimed and verified businesses also display a badge.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I reply to a business review?',
				'answer'   => 'Open the Reviews section in your business portal and choose Reply under any review. Public replies appear on your business page and show customers you care.',
			),
			array(
				'module'   => 'business',
				'question' => 'What business categories are supported?',
				'answer'   => 'The directory covers cafes, restaurants, barbers, plumbers, clinics, gyms, hotels, salons, mechanics, bakeries and more. If your category is missing, contact the admin and it can be added.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I contact a business?',
				'answer'   => 'Open the business page and use the contact details listed there — email, phone or website. Some listings include a direction link to their physical address.',
			),
			array(
				'module'   => 'business',
				'question' => 'What time is Zeko Central Cafe open?',
				'answer'   => 'The demo café\'s weekly opening hours are shown on its page at /businesses/zeko-central-cafe-bistro/. Open it and check the Opening hours section for each day of the week.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do I get more customers for my business?',
				'answer'   => 'Complete every profile field, go through business verification, collect good reviews, and consider featured or sponsored placement. The directory ranks sponsored first, then featured, then regular listings by rating and recency.',
			),
			array(
				'module'   => 'business',
				'question' => 'How do business listings get ranked?',
				'answer'   => 'Search results in the directory are ordered sponsored first, then featured, then regular listings sorted by average rating and how recently the business posted or was updated.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I cancel an order?',
				'answer'   => 'Open the order in your dashboard and contact the seller through the order page. Unshipped orders can usually be cancelled and any payment returned to your Zeko wallet.',
			),
			array(
				'module'   => 'shop',
				'question' => 'How do I contact a seller?',
				'answer'   => 'Open the order or product page and use the seller contact options. Messaging the seller first resolves most questions faster than opening a refund or dispute.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I delete a job posting?',
				'answer'   => 'Open your posted jobs from the dashboard, choose the listing, and select Remove or Delete. The posting leaves search immediately and applicants are notified.',
			),
			array(
				'module'   => 'jobs',
				'question' => 'How do I re-open a closed job posting?',
				'answer'   => 'Open the closed listing from your dashboard and choose Re-open. It becomes active and searchable again with its original details and applicants.',
			),
			array(
				'module'   => 'learn',
				'question' => 'Can I retake a lesson quiz?',
				'answer'   => 'Yes. If you do not pass on the first try, you can retake a quiz after a short cooldown. Your best score is kept as your progress.',
			),
			array(
				'module'   => 'learn',
				'question' => 'How do I leave a course?',
				'answer'   => 'Open the course page and choose Leave course or Unenroll from your learning dashboard. Your progress is kept unless you confirm a full reset.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I delete a question?',
				'answer'   => 'Open the question page and choose Delete. Deleted questions are removed from search, feeds and your profile activity.',
			),
			array(
				'module'   => 'qa',
				'question' => 'How do I change the accepted answer?',
				'answer'   => 'If you asked the question, choose Accept on a different answer to move the accepted mark. The previously accepted answer keeps its reputation.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I cancel a freelance project?',
				'answer'   => 'Both sides can agree to cancel a project from the project page. Approved cancellation releases any escrow funds back to the client.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'What happens if a freelancer does not deliver?',
				'answer'   => 'Open the project and choose Dispute so support can review the milestones and conversation. Milestone funds stay in escrow until the dispute is resolved.',
			),
			array(
				'module'   => 'freelance',
				'question' => 'How do I set my freelance rate?',
				'answer'   => 'On your freelance profile set an hourly rate or fixed price range, your skills and availability. Complete profiles with a clear rate win more bids.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How long is a mentoring session?',
				'answer'   => 'Each mentor sets their own session length, usually between 30 and 60 minutes. Check the mentor profile before booking.',
			),
			array(
				'module'   => 'mentor',
				'question' => 'How do I cancel a mentoring session?',
				'answer'   => 'Open the session in your mentoring dashboard and choose Cancel. Give your mentor advance notice so they can fill the slot.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I hide my dating profile?',
				'answer'   => 'Open your dating settings and switch off Show me in search. You stay logged in and can turn membership visibility back on anytime.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I delete my dating profile?',
				'answer'   => 'Open dating settings and choose Remove profile. Removing it takes you out of matches and search; your account and other Zeko profiles are unaffected.',
			),
			array(
				'module'   => 'love',
				'question' => 'How do I report a fake dating profile?',
				'answer'   => 'Open the profile and choose Report, then pick the reason. Moderators review the report and may ask for evidence before action.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I add a payout method?',
				'answer'   => 'Open your wallet, choose Withdraw and add a payout method such as a bank account or card. Your details must be verified before the first payout.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'How do I dispute a wallet payment?',
				'answer'   => 'Open the transaction in your wallet and choose Dispute, then describe the issue. Support reviews the payment details and responds from the platform.',
			),
			array(
				'module'   => 'wallet',
				'question' => 'What is my wallet ID and account number used for?',
				'answer'   => 'Your Zeko account number appears in your wallet tab. Share it with employers, clients and businesses to receive payments, and track everything in your transactions list.',
			),
			array(
				'module'   => 'rewards',
				'question' => 'How do I reach the next rewards tier?',
				'answer'   => 'Tiers are earned with activity across the ecosystem — learning, helping, working and referring others. Your tier badge shows your current level on the Rewards dashboard.',
			),
			array(
				'module'   => 'moderation',
				'question' => 'How do I protect my account from scams?',
				'answer'   => 'Never share passwords, payment details or verification codes. Deal only through the platform, keep your account number private, and report anything that feels like a scam.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do I delete an AI conversation?',
				'answer'   => 'Open your AI conversations and choose Delete on any thread. The conversation and its messages are removed from your dashboard.',
			),
			array(
				'module'   => 'general',
				'question' => 'Where is my personal data stored?',
				'answer'   => 'Your profile, posts and activity live in the platform database, and the privacy policy explains what is collected and how it is used. Personal AI memory is stored per member and can be cleared anytime from AI settings.',
			),
			array(
				'module'   => 'general',
				'question' => 'How do ratings affect my business or freelance success?',
				'answer'   => 'Business listings with higher average ratings rank higher in the directory, and well-rated freelancers and mentors win more work. Reviews matter, so keep delivering quality.',
			),
			array(
				'module'   => 'search',
				'question' => 'How do I search for a specific type of business?',
				'answer'   => 'Open the directory and filter by category, or just ask the assistant — for example "find me a cafe or restaurant near me" — and it will look up businesses and show you their pages.',
			),
		);

		return apply_filters( 'zeko_ai_agent_seed_knowledge', $seeds );
	}
}
