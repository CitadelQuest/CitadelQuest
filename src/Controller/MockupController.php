<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/mockups')]
class MockupController extends AbstractController
{
    #[Route('/public-project/{style}', name: 'app_mockup_public_project')]
    public function publicProject(string $style = '1'): Response
    {
        // Sample project data for mockup
        $project = [];
        
        // If therapy mockup is requested, use therapy-specific data
        if ($style === 'therapy') {
            $project = [
                'id' => 'proj-789012',
                'title' => 'MindfulJourney Therapy',
                'slug' => 'mindfuljourney-therapy',
                'description' => 'A comprehensive online therapy platform focused on mental wellness and personal growth.',
                'is_public' => true,
                'is_active' => true,
                'owner' => [
                    'username' => 'dr_sarah',
                    'display_name' => 'Sarah Johnson',
                    'avatar' => 'https://i.pravatar.cc/150?u=dr_sarah'
                ],
                'created_at' => new \DateTime('2025-05-15'),
                'updated_at' => new \DateTime('2025-06-01'),
                'progress' => 80, // Percentage complete
                'files' => [
                    ['name' => 'therapy-resources.pdf', 'type' => 'pdf', 'size' => '3.2 MB', 'updated_at' => new \DateTime('2025-05-28'), 'is_public' => true, 'description' => 'Comprehensive therapy resources and worksheets'],
                    ['name' => 'mindfulness-guide.pdf', 'type' => 'pdf', 'size' => '2.1 MB', 'updated_at' => new \DateTime('2025-05-30'), 'is_public' => true, 'description' => 'Guide to mindfulness practices and exercises'],
                    ['name' => 'cbt-workbook.pdf', 'type' => 'pdf', 'size' => '4.5 MB', 'updated_at' => new \DateTime('2025-06-01'), 'is_public' => true, 'description' => 'Cognitive Behavioral Therapy workbook'],
                ],
                'online_sources' => [
                    ['id' => 'src-1', 'title' => 'Understanding Anxiety', 'type' => 'article', 'url' => 'https://example.com/anxiety-guide', 'is_public' => true, 'description' => 'Comprehensive guide to understanding and managing anxiety'],
                    ['id' => 'src-2', 'title' => 'Meditation Techniques', 'type' => 'video', 'url' => 'https://example.com/meditation-video', 'is_public' => true, 'description' => 'Video series on effective meditation techniques'],
                    ['id' => 'src-3', 'title' => 'Sleep Improvement', 'type' => 'podcast', 'url' => 'https://example.com/sleep-podcast', 'is_public' => true, 'description' => 'Podcast episodes about improving sleep quality'],
                ],
                'readme_content' => "# MindfulJourney Therapy Platform\n\n## Our Approach\n\nAt MindfulJourney, we believe in a holistic approach to mental wellness. Our therapy services combine evidence-based practices with personalized care to help you navigate life's challenges and achieve lasting positive change.\n\n## Our Philosophy\n\nWe are committed to:\n\n1. **Accessibility**: Making quality mental health care available to everyone\n2. **Personalization**: Tailoring our approach to your unique needs and goals\n3. **Integration**: Combining various therapeutic modalities for comprehensive care\n4. **Empowerment**: Providing you with tools and knowledge for self-management\n5. **Support**: Creating a safe, non-judgmental space for growth and healing\n\n## How We Work\n\nOur platform connects you with licensed therapists who specialize in various areas of mental health. Through secure video sessions, messaging, and interactive tools, you can engage in therapy that fits your schedule and preferences.\n\nWe offer both individual and group therapy options, as well as self-guided resources to support your journey between sessions.\n\n## Getting Started\n\nBegin by scheduling a free consultation with one of our therapists. During this session, you'll discuss your goals and preferences, and together you'll create a personalized treatment plan.\n",
                'collaborators' => [
                    ['username' => 'dr_michael', 'display_name' => 'Michael Chen', 'avatar' => 'https://i.pravatar.cc/150?u=dr_michael', 'role' => 'Family Therapist'],
                    ['username' => 'dr_elena', 'display_name' => 'Elena Rodriguez', 'avatar' => 'https://i.pravatar.cc/150?u=dr_elena', 'role' => 'Anxiety Specialist'],
                    ['username' => 'dr_james', 'display_name' => 'James Wilson', 'avatar' => 'https://i.pravatar.cc/150?u=dr_james', 'role' => 'Mindfulness Coach'],
                ]
            ];
        } else {
            // Default project data for other mockups
            $project = [
                'id' => 'proj-123456',
                'title' => 'Brovolenka 2025',
                'slug' => 'brovolenka-2025',
                'description' => 'Annual vacation planning for our group of 10 friends across different cities.',
                'is_public' => true,
                'is_active' => true,
                'owner' => [
                    'username' => 'phaanko',
                    'display_name' => 'Phaanko',
                    'avatar' => 'https://i.pravatar.cc/150?u=phaanko'
                ],
                'created_at' => new \DateTime('2025-06-01'),
                'updated_at' => new \DateTime('2025-06-01'),
                'progress' => 65, // Percentage complete
                'files' => [
                    ['name' => 'readme.md', 'type' => 'markdown', 'size' => '2.4 KB', 'updated_at' => new \DateTime('2025-06-01'), 'is_public' => true],
                    ['name' => 'implementation-plan.md', 'type' => 'markdown', 'size' => '1.8 KB', 'updated_at' => new \DateTime('2025-06-01'), 'is_public' => true],
                    ['name' => 'locations.json', 'type' => 'json', 'size' => '4.2 KB', 'updated_at' => new \DateTime('2025-06-01'), 'is_public' => false],
                ],
                'online_sources' => [
                    ['id' => 'src-1', 'title' => 'Mountain Cabin Options', 'type' => 'webpage', 'url' => 'https://example.com/cabins', 'is_public' => true],
                    ['id' => 'src-2', 'title' => 'Travel Regulations 2025', 'type' => 'pdf', 'url' => 'https://example.com/travel-regs.pdf', 'is_public' => true],
                ],
                'readme_content' => "# Brovolenka 2025\n\n## Project Overview\n\nThis project aims to organize our annual 'Brovolenka' vacation for our group of 10 friends. We need to coordinate dates, location, activities, and logistics across different cities and countries.\n\n## Objectives\n\n1. Select optimal dates (July-August 2025)\n2. Research and choose location (mountains preferred)\n3. Plan activities and logistics\n4. Coordinate travel arrangements\n5. Create budget and payment system\n\n## Timeline\n\n- June: Research and decision on location\n- July: Booking and detailed planning\n- August: Final preparations\n- September: Execution and enjoyment!\n",
                'milestones' => [
                    ['title' => 'Location Selection', 'due_date' => new \DateTime('2025-06-15'), 'completed' => true],
                    ['title' => 'Accommodation Booking', 'due_date' => new \DateTime('2025-07-01'), 'completed' => true],
                    ['title' => 'Activity Planning', 'due_date' => new \DateTime('2025-07-15'), 'completed' => false],
                    ['title' => 'Travel Arrangements', 'due_date' => new \DateTime('2025-08-01'), 'completed' => false],
                ],
                'collaborators' => [
                    ['username' => 'martin', 'display_name' => 'Martin', 'avatar' => 'https://i.pravatar.cc/150?u=martin'],
                    ['username' => 'lucia', 'display_name' => 'Lucia', 'avatar' => 'https://i.pravatar.cc/150?u=lucia'],
                    ['username' => 'tomas', 'display_name' => 'Tomas', 'avatar' => 'https://i.pravatar.cc/150?u=tomas'],
                ]
            ];
        }
        
        return $this->render('mockups/public_project_' . $style . '.html.twig', [
            'project' => $project,
        ]);
    }
    #[Route('/project-detail-1', name: 'app_mockup_project_detail_1')]
    public function projectDetail1(): Response
    {
        // Sample project data for mockup
        $project = [
            'id' => 'proj-123456',
            'title' => 'Brovolenka 2025',
            'slug' => 'brovolenka-2025',
            'description' => 'Annual vacation planning for our group of 10 friends across different cities.',
            'is_public' => true,
            'is_active' => true,
            'created_at' => new \DateTime('2025-06-01'),
            'updated_at' => new \DateTime('2025-06-01'),
            'tools' => [
                ['id' => 'tool-1', 'name' => 'File Manager', 'icon' => 'mdi mdi-folder'],
                ['id' => 'tool-2', 'name' => 'Web Browser', 'icon' => 'mdi mdi-web'],
                ['id' => 'tool-3', 'name' => 'PDF Generator', 'icon' => 'mdi mdi-file-pdf-box'],
            ],
            'conversations' => [
                ['id' => 'conv-1', 'title' => 'Initial Planning', 'created_at' => new \DateTime('2025-06-01')],
                ['id' => 'conv-2', 'title' => 'Location Research', 'created_at' => new \DateTime('2025-06-01')],
            ],
            'files' => [
                ['name' => 'readme.md', 'type' => 'markdown', 'size' => '2.4 KB', 'updated_at' => new \DateTime('2025-06-01')],
                ['name' => 'implementation-plan.md', 'type' => 'markdown', 'size' => '1.8 KB', 'updated_at' => new \DateTime('2025-06-01')],
                ['name' => 'locations.json', 'type' => 'json', 'size' => '4.2 KB', 'updated_at' => new \DateTime('2025-06-01')],
            ],
            'online_sources' => [
                ['id' => 'src-1', 'title' => 'Mountain Cabin Options', 'type' => 'webpage', 'url' => 'https://example.com/cabins'],
                ['id' => 'src-2', 'title' => 'Travel Regulations 2025', 'type' => 'pdf', 'url' => 'https://example.com/travel-regs.pdf'],
            ],
            'readme_content' => "# Brovolenka 2025\n\n## Project Overview\n\nThis project aims to organize our annual 'Brovolenka' vacation for our group of 10 friends. We need to coordinate dates, location, activities, and logistics across different cities and countries.\n\n## Objectives\n\n1. Select optimal dates (July-August 2025)\n2. Research and choose location (mountains preferred)\n3. Plan activities and logistics\n4. Coordinate travel arrangements\n5. Create budget and payment system\n\n## Timeline\n\n- June: Research and decision on location\n- July: Booking and detailed planning\n- August: Final preparations\n- September: Execution and enjoyment!\n"
        ];
        
        return $this->render('mockups/project_detail_1.html.twig', [
            'project' => $project,
        ]);
    }
    
    #[Route('/project-detail-2', name: 'app_mockup_project_detail_2')]
    public function projectDetail2(): Response
    {
        // Sample project data for mockup
        $project = [
            'id' => 'proj-789012',
            'title' => 'Tiny House Design',
            'slug' => 'tiny-house-design',
            'description' => 'Designing and planning a sustainable tiny house with modern amenities.',
            'is_public' => false,
            'is_active' => true,
            'created_at' => new \DateTime('2025-05-15'),
            'updated_at' => new \DateTime('2025-06-01'),
            'tools' => [
                ['id' => 'tool-1', 'name' => 'File Manager', 'icon' => 'mdi mdi-folder'],
                ['id' => 'tool-4', 'name' => '3D Visualizer', 'icon' => 'mdi mdi-cube'],
                ['id' => 'tool-5', 'name' => 'Materials Calculator', 'icon' => 'mdi mdi-calculator'],
            ],
            'conversations' => [
                ['id' => 'conv-1', 'title' => 'Design Requirements', 'created_at' => new \DateTime('2025-05-15')],
                ['id' => 'conv-2', 'title' => 'Material Selection', 'created_at' => new \DateTime('2025-05-20')],
                ['id' => 'conv-3', 'title' => 'Layout Optimization', 'created_at' => new \DateTime('2025-05-25')],
            ],
            'files' => [
                ['name' => 'readme.md', 'type' => 'markdown', 'size' => '3.1 KB', 'updated_at' => new \DateTime('2025-05-15')],
                ['name' => 'design-specs.md', 'type' => 'markdown', 'size' => '5.7 KB', 'updated_at' => new \DateTime('2025-05-28')],
                ['name' => 'materials.json', 'type' => 'json', 'size' => '8.3 KB', 'updated_at' => new \DateTime('2025-05-30')],
                ['name' => 'floor-plan.svg', 'type' => 'svg', 'size' => '245 KB', 'updated_at' => new \DateTime('2025-06-01')],
            ],
            'online_sources' => [
                ['id' => 'src-1', 'title' => 'Sustainable Building Materials', 'type' => 'webpage', 'url' => 'https://example.com/eco-materials'],
                ['id' => 'src-2', 'title' => 'Tiny House Regulations', 'type' => 'pdf', 'url' => 'https://example.com/tiny-regs.pdf'],
                ['id' => 'src-3', 'title' => 'Solar Panel Efficiency Data', 'type' => 'dataset', 'url' => 'https://example.com/solar-data'],
            ],
            'readme_content' => "# Tiny House Design Project\n\n## Vision\n\nCreate a sustainable, off-grid tiny house design that maximizes space efficiency while maintaining comfort and modern amenities.\n\n## Key Requirements\n\n1. Maximum size: 250 sq ft footprint\n2. Solar power system with battery storage\n3. Rainwater collection and filtration\n4. Composting toilet system\n5. Multi-functional furniture\n6. Full kitchen with propane cooking\n7. Sleeping loft for queen mattress\n8. Dedicated workspace\n\n## Construction Approach\n\nUsing SIPs (Structural Insulated Panels) for main construction with timber frame accents. Focus on materials with low environmental impact and high insulation values.\n"
        ];
        
        return $this->render('mockups/project_detail_2.html.twig', [
            'project' => $project,
        ]);
    }
    
    #[Route('/project-detail-3', name: 'app_mockup_project_detail_3')]
    public function projectDetail3(): Response
    {
        // Sample project data for mockup
        $project = [
            'id' => 'proj-345678',
            'title' => 'Online Therapy Platform',
            'slug' => 'online-therapy-platform',
            'description' => 'Specialized platform for psychotherapy clients with AI Spirit assistance.',
            'is_public' => false,
            'is_active' => true,
            'created_at' => new \DateTime('2025-04-10'),
            'updated_at' => new \DateTime('2025-06-01'),
            'tools' => [
                ['id' => 'tool-1', 'name' => 'File Manager', 'icon' => 'mdi mdi-folder'],
                ['id' => 'tool-2', 'name' => 'Web Browser', 'icon' => 'mdi mdi-web'],
                ['id' => 'tool-6', 'name' => 'Mood Tracker', 'icon' => 'mdi mdi-chart-line'],
                ['id' => 'tool-7', 'name' => 'Journal Assistant', 'icon' => 'mdi mdi-book'],
            ],
            'conversations' => [
                ['id' => 'conv-1', 'title' => 'Platform Requirements', 'created_at' => new \DateTime('2025-04-10')],
                ['id' => 'conv-2', 'title' => 'Therapy Approach Research', 'created_at' => new \DateTime('2025-04-15')],
                ['id' => 'conv-3', 'title' => 'Client Journey Mapping', 'created_at' => new \DateTime('2025-04-22')],
                ['id' => 'conv-4', 'title' => 'Privacy Considerations', 'created_at' => new \DateTime('2025-05-03')],
                ['id' => 'conv-5', 'title' => 'Integration Planning', 'created_at' => new \DateTime('2025-05-18')],
            ],
            'files' => [
                ['name' => 'readme.md', 'type' => 'markdown', 'size' => '4.2 KB', 'updated_at' => new \DateTime('2025-04-10')],
                ['name' => 'client-journey.md', 'type' => 'markdown', 'size' => '7.8 KB', 'updated_at' => new \DateTime('2025-04-25')],
                ['name' => 'privacy-policy.md', 'type' => 'markdown', 'size' => '12.3 KB', 'updated_at' => new \DateTime('2025-05-05')],
                ['name' => 'therapy-approaches.json', 'type' => 'json', 'size' => '15.7 KB', 'updated_at' => new \DateTime('2025-05-20')],
                ['name' => 'platform-wireframes.svg', 'type' => 'svg', 'size' => '320 KB', 'updated_at' => new \DateTime('2025-05-28')],
            ],
            'online_sources' => [
                ['id' => 'src-1', 'title' => 'Digital Therapy Ethics Guidelines', 'type' => 'pdf', 'url' => 'https://example.com/ethics.pdf'],
                ['id' => 'src-2', 'title' => 'AI in Mental Health Research', 'type' => 'academic', 'url' => 'https://example.com/ai-mental-health'],
                ['id' => 'src-3', 'title' => 'Patient Data Security Standards', 'type' => 'webpage', 'url' => 'https://example.com/data-security'],
                ['id' => 'src-4', 'title' => 'Therapeutic Alliance in Digital Spaces', 'type' => 'video', 'url' => 'https://example.com/alliance-video'],
            ],
            'readme_content' => "# Online Therapy Platform\n\n## Vision\n\nCreate a specialized platform that combines traditional psychotherapy with AI Spirit assistance to provide comprehensive mental health support.\n\n## Key Components\n\n1. Secure video consultation system\n2. Client journal with AI-assisted reflection\n3. Mood and behavior tracking tools\n4. Resource library with personalized recommendations\n5. Therapist dashboard for client progress monitoring\n6. Emergency support protocols\n7. Specialized AI Spirit with therapeutic training\n\n## Ethical Framework\n\nAll aspects of this platform will adhere to the highest standards of:\n- Client confidentiality and data privacy\n- Informed consent processes\n- Clear boundaries between human and AI support\n- Evidence-based therapeutic approaches\n- Regular ethical review and oversight\n\nThis platform will serve as an extension of traditional therapy, not a replacement.\n"
        ];
        
        return $this->render('mockups/project_detail_3.html.twig', [
            'project' => $project,
        ]);
    }
}
