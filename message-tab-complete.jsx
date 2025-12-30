import React, { useState } from 'react';

const MessageTabDualModeMockup = () => {
  const [isComposing, setIsComposing] = useState(false);
  const [mode, setMode] = useState('single');
  const [selectedTemplate, setSelectedTemplate] = useState('');
  const [selectedSequence, setSelectedSequence] = useState('intro');
  const [expandedStep, setExpandedStep] = useState(null);
  const [editingStep, setEditingStep] = useState(null);
  const [previewMode, setPreviewMode] = useState(true);
  const [showSaveModal, setShowSaveModal] = useState(false);
  const [saveModalType, setSaveModalType] = useState('update');
  const [newTemplateName, setNewTemplateName] = useState('');
  const [showAIPanel, setShowAIPanel] = useState(false);
  const [aiPrompt, setAiPrompt] = useState('');
  const [aiGenerating, setAiGenerating] = useState(false);
  const [aiTone, setAiTone] = useState('professional');
  const [aiLength, setAiLength] = useState('medium');
  const [copied, setCopied] = useState(false);
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [stepEdits, setStepEdits] = useState({});
  const [expandedSections, setExpandedSections] = useState({
    messaging: true,
    podcast: false,
    contact: false
  });

  const resolvedValues = {
    podcast_name: 'Write Your Book in a Flash',
    host_name: 'Dan',
    episode_count: '245',
    recent_episode: 'How to Market Your Book in 2024',
    contact_email: 'dan@writeyourbookinaflash.com',
    contact_first_name: 'Dan',
    booking_link: 'calendly.com/danjanal',
    authority_hook: 'I help authors and coaches turn their expertise into bestselling books',
    impact_intro: "I've helped 200+ entrepreneurs write and publish their books",
    who_you_help: 'authors and thought leaders',
    when_they_need: "they're ready to establish authority in their industry",
    what_result: 'write and publish a book in 90 days',
    how_you_help: 'through my proven 90-day book writing system',
    where_authority: 'helped 200+ authors get published, including 12 Amazon bestsellers',
    why_passionate: 'I believe everyone has a story worth sharing',
  };

  const resolveVariables = (text) => {
    if (!text) return '';
    let resolved = text;
    Object.entries(resolvedValues).forEach(([key, value]) => {
      resolved = resolved.replace(new RegExp(`\\{\\{${key}\\}\\}`, 'g'), value);
    });
    return resolved;
  };

  const toggleSection = (section) => {
    setExpandedSections(prev => ({
      ...prev,
      [section]: !prev[section]
    }));
  };

  const insertVariable = (variable) => {
    if (mode === 'single') {
      setMessage(prev => prev + `{{${variable}}}`);
    } else if (editingStep !== null) {
      setStepEdits(prev => ({
        ...prev,
        [editingStep]: {
          ...prev[editingStep],
          body: (prev[editingStep]?.body || currentSequence?.steps[editingStep]?.body || '') + `{{${variable}}}`
        }
      }));
    }
  };

  const handleCopyBody = () => {
    const textToCopy = resolveVariables(message);
    navigator.clipboard.writeText(textToCopy);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleOpenInEmail = () => {
    const resolvedSubject = encodeURIComponent(resolveVariables(subject));
    const resolvedBody = encodeURIComponent(resolveVariables(message));
    const mailtoLink = `mailto:${resolvedValues.contact_email}?subject=${resolvedSubject}&body=${resolvedBody}`;
    window.open(mailtoLink, '_blank');
  };

  const handleSaveDraft = () => {
    alert('Draft saved!');
  };

  const handleMarkAsSent = () => {
    alert('Email marked as sent and added to message history.');
    setIsComposing(false);
  };

  const templates = [
    { id: '', name: '-- No Template --' },
    { id: 'framework', name: 'Framework Pitch' },
    { id: 'casestudy', name: 'Case Study Pitch' },
    { id: 'thought', name: 'Thought Leadership' },
  ];

  const sequences = [
    { 
      id: 'intro', 
      name: 'Intro (2 steps)',
      description: 'intro',
      steps: [
        { 
          name: 'Intro', 
          delay: null,
          subject: 'Perfect guest for {{podcast_name}}',
          body: `Hi {{host_name}},

I recently listened to your episode about {{recent_episode}} and loved your perspective.

{{authority_hook}}

I'd love to share my framework on {{what_result}} with your audience. Would you be open to a quick chat?

Best,
Tony`
        },
        { 
          name: 'Intro 22', 
          delay: '+3 days',
          subject: 'Re: Perfect guest for {{podcast_name}}',
          body: `Hi {{host_name}},

Just wanted to follow up on my previous note. I know you're busy, but I think your audience would really benefit from learning about {{what_result}}.

{{where_authority}}

Would love to connect if you have 15 minutes this week.

Best,
Tony`
        }
      ]
    },
    { 
      id: 'followup', 
      name: 'Follow-up (3 steps)',
      description: 'follow-up sequence',
      steps: [
        { 
          name: 'Initial Outreach', 
          delay: null,
          subject: 'Quick question about {{podcast_name}}',
          body: `Hi {{host_name}},

Big fan of {{podcast_name}}. {{impact_intro}}

Would love to share some insights with your listeners.

Best,
Tony`
        },
        { 
          name: 'Gentle Nudge', 
          delay: '+4 days',
          subject: 'Re: Quick question about {{podcast_name}}',
          body: `Hi {{host_name}},

Following up on my note from last week. I've got some great content on {{what_result}} that I think would resonate with your audience.

Let me know if you'd like to chat!

Tony`
        },
        { 
          name: 'Final Follow-up', 
          delay: '+7 days',
          subject: 'One last note',
          body: `Hi {{host_name}},

I'll keep this brief - if podcasting guests isn't a priority right now, no worries at all.

But if you're ever looking for someone to discuss {{what_result}}, I'd love to be considered.

Thanks for all the great content!

Tony`
        }
      ]
    },
  ];

  const variables = {
    messaging: [
      { key: 'authority_hook', label: 'Your Authority Hook', preview: 'I help Authors launching...' },
      { key: 'impact_intro', label: 'Your Impact Intro', preview: "I've helped 200+ startups..." },
      { key: 'who_you_help', label: 'WHO do you help? (specific niches)', preview: 'saas founder' },
      { key: 'when_they_need', label: 'WHEN do they need you?', preview: "they're scaling rapidly" },
      { key: 'what_result', label: 'WHAT result do you help them achieve?', preview: 'increase revenue by 40%' },
      { key: 'how_you_help', label: 'HOW do you help them achieve this result?', preview: 'through my proven 90-day...' },
      { key: 'where_authority', label: 'WHERE have you demonstrated results/credentials?', preview: 'helped 200+ startups I, f...' },
      { key: 'why_passionate', label: 'WHY are you passionate about what you do?', preview: 'help thought leaders ampl...' },
    ],
    podcast: [
      { key: 'podcast_name', label: 'Podcast Name', preview: 'Write Your Book in a Flash' },
      { key: 'host_name', label: 'Host Name', preview: 'Dan Janal' },
      { key: 'episode_count', label: 'Episode Count', preview: '245' },
      { key: 'recent_episode', label: 'Recent Episode Title', preview: 'How to Market Your Book' },
    ],
    contact: [
      { key: 'contact_email', label: 'Contact Email', preview: 'dan@example.com' },
      { key: 'contact_first_name', label: 'First Name', preview: 'Dan' },
      { key: 'booking_link', label: 'Booking Link', preview: 'calendly.com/dan' },
    ]
  };

  const aiQuickActions = [
    { label: 'Make it shorter', icon: '📏' },
    { label: 'Make it more personal', icon: '💬' },
    { label: 'Add social proof', icon: '⭐' },
    { label: 'Stronger CTA', icon: '🎯' },
    { label: 'Reference recent episode', icon: '🎙️' },
    { label: 'Add urgency', icon: '⏰' },
  ];

  const previousMessages = [
    { email: 'tony@bigfishresults.com', subject: 'test message', status: 'Sent', date: '1 week ago', type: 'single' }
  ];

  const activeCampaigns = [
    { sequence: 'Intro (2 steps)', status: 'Step 1 of 2', nextStep: 'in 3 days', recipient: 'dan@example.com' }
  ];

  const currentSequence = sequences.find(s => s.id === selectedSequence);

  const toggleStep = (idx) => {
    if (expandedStep === idx) {
      setExpandedStep(null);
      setEditingStep(null);
    } else {
      setExpandedStep(idx);
    }
  };

  const startEditingStep = (idx) => {
    setEditingStep(idx);
    setPreviewMode(false);
    if (!stepEdits[idx]) {
      setStepEdits(prev => ({
        ...prev,
        [idx]: {
          subject: currentSequence?.steps[idx]?.subject || '',
          body: currentSequence?.steps[idx]?.body || ''
        }
      }));
    }
  };

  const cancelEditingStep = () => {
    setEditingStep(null);
    setPreviewMode(true);
    setShowAIPanel(false);
  };

  const getStepContent = (idx) => {
    return stepEdits[idx] || currentSequence?.steps[idx] || { subject: '', body: '' };
  };

  const openSaveModal = (type) => {
    setSaveModalType(type);
    setNewTemplateName('');
    setShowSaveModal(true);
  };

  const handleSaveTemplate = () => {
    if (saveModalType === 'update') {
      alert(`Template "${currentSequence?.steps[editingStep]?.name}" updated!`);
    } else {
      alert(`New template "${newTemplateName}" created!`);
    }
    setShowSaveModal(false);
    setEditingStep(null);
    setPreviewMode(true);
  };

  const handleAIGenerate = () => {
    setAiGenerating(true);
    setTimeout(() => {
      const generatedContent = `Hi {{host_name}},

I just finished listening to your episode "{{recent_episode}}" and was impressed by your deep dive into the topic.

{{authority_hook}}. My approach has {{where_authority}}.

I have a unique framework for {{what_result}} that I believe would provide tremendous value to your listeners who are {{who_you_help}}.

Would you be open to a 15-minute call to explore if this could be a fit for {{podcast_name}}?

Best regards,
Tony`;
      
      if (mode === 'single') {
        setMessage(generatedContent);
        setSubject(`Podcast Guest Pitch: {{what_result}} for {{podcast_name}}`);
      } else if (editingStep !== null) {
        setStepEdits(prev => ({
          ...prev,
          [editingStep]: {
            subject: `Podcast Guest Pitch: {{what_result}} for {{podcast_name}}`,
            body: generatedContent
          }
        }));
      }
      setAiGenerating(false);
      setShowAIPanel(false);
    }, 1500);
  };

  const handleQuickAction = (action) => {
    setAiPrompt(action);
    handleAIGenerate();
  };

  const showVariablesSidebar = mode === 'single' || expandedStep !== null;

  return (
    <div className="min-h-screen bg-gray-100 text-sm">
      {/* Save Template Modal */}
      {showSaveModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl w-96 p-4">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-semibold text-gray-900">
                {saveModalType === 'update' ? 'Update Template' : 'Save as New Template'}
              </h3>
              <button onClick={() => setShowSaveModal(false)} className="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            
            {saveModalType === 'update' ? (
              <div className="mb-4">
                <div className="p-3 bg-yellow-50 border border-yellow-200 rounded-lg mb-4">
                  <p className="text-xs text-yellow-800">
                    ⚠️ This will update the <strong>"{currentSequence?.steps[editingStep]?.name}"</strong> template for all future campaigns using this sequence.
                  </p>
                </div>
                <p className="text-xs text-gray-600">
                  Existing campaigns already in progress will not be affected.
                </p>
              </div>
            ) : (
              <div className="mb-4">
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  New Template Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={newTemplateName}
                  onChange={(e) => setNewTemplateName(e.target.value)}
                  placeholder="e.g., Book Author Intro v2"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                  autoFocus
                />
                <p className="text-xs text-gray-500 mt-2">
                  This will create a new standalone email template that you can use in any sequence.
                </p>
              </div>
            )}
            
            <div className="flex items-center gap-2 justify-end pt-3 border-t">
              <button 
                onClick={() => setShowSaveModal(false)}
                className="px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded"
              >
                Cancel
              </button>
              <button 
                onClick={handleSaveTemplate}
                disabled={saveModalType === 'new' && !newTemplateName.trim()}
                className={`px-4 py-1.5 text-sm text-white rounded ${
                  saveModalType === 'new' && !newTemplateName.trim()
                    ? 'bg-gray-300 cursor-not-allowed'
                    : 'bg-blue-500 hover:bg-blue-600'
                }`}
              >
                {saveModalType === 'update' ? 'Update Template' : 'Create Template'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Header */}
      <div className="bg-slate-700 text-white px-4 py-2 flex items-center gap-6 text-xs">
        <span className="opacity-70">🔍 Podcast Prospector</span>
        <span className="opacity-70">👥 Interview Tracker</span>
        <span className="font-medium">✉️ Message Builder</span>
      </div>

      {/* Back Link */}
      <div className="bg-white border-b px-4 py-2">
        <button className="text-blue-500 hover:text-blue-600 flex items-center gap-1 text-xs">
          ← Back to Interviews
        </button>
      </div>

      {/* Podcast Header */}
      <div className="bg-white mx-4 mt-3 rounded-lg shadow-sm border p-4">
        <div className="flex items-start gap-4">
          <div className="w-14 h-14 bg-gradient-to-br from-blue-400 to-blue-600 rounded-lg flex items-center justify-center text-white text-xl flex-shrink-0">📚</div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="font-semibold text-gray-900 truncate">Write Your Book in a Flash Podcast</h1>
              <span className="px-2 py-0.5 bg-yellow-100 text-yellow-700 text-xs rounded-full font-medium whitespace-nowrap">● Medium</span>
            </div>
            <p className="text-gray-500 text-xs mt-0.5">Dan Janal • Last release: Nov 3, 2024</p>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white mx-4 mt-3 rounded-t-lg border-b">
        <div className="flex overflow-x-auto">
          {['About', 'Listen', 'Contact', 'Message', 'Tasks', 'Notes'].map((tab) => (
            <button key={tab} className={`px-4 py-3 text-xs font-medium border-b-2 whitespace-nowrap ${tab === 'Message' ? 'text-blue-500 border-blue-500' : 'text-gray-500 border-transparent hover:text-gray-700'}`}>
              {tab}
            </button>
          ))}
        </div>
      </div>

      {/* Content */}
      <div className="bg-white mx-4 rounded-b-lg shadow-sm border border-t-0 mb-4">
        {/* Stats */}
        <div className="px-4 py-3 bg-gray-50 border-b">
          <div className="flex gap-6">
            <div className="text-center">
              <div className="text-xl font-bold text-blue-500">1</div>
              <div className="text-xs text-gray-500 uppercase">Sent</div>
            </div>
            <div className="text-center">
              <div className="text-xl font-bold text-gray-300">0</div>
              <div className="text-xs text-gray-500 uppercase">Opened</div>
            </div>
            <div className="text-center">
              <div className="text-xl font-bold text-gray-300">0</div>
              <div className="text-xs text-gray-500 uppercase">Clicked</div>
            </div>
            <div className="border-l pl-6 text-center">
              <div className="text-xl font-bold text-emerald-500">1</div>
              <div className="text-xs text-gray-500 uppercase">Active Campaign</div>
            </div>
          </div>
        </div>

        <div className="p-4">
          {!isComposing ? (
            /* DEFAULT STATE */
            <div>
              <div className="flex items-center justify-between mb-3">
                <h2 className="font-semibold text-gray-900">Messages & Campaigns</h2>
                <button onClick={() => setIsComposing(true)} className="flex items-center gap-1.5 px-3 py-1.5 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-xs">
                  <span>+</span> Compose Email
                </button>
              </div>

              {activeCampaigns.length > 0 && (
                <div className="mb-4">
                  <h3 className="text-xs font-medium text-gray-500 uppercase mb-2">Active Campaigns</h3>
                  <div className="border rounded-lg bg-emerald-50 border-emerald-200">
                    {activeCampaigns.map((campaign, idx) => (
                      <div key={idx} className="p-3 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white text-xs">▶</div>
                          <div>
                            <p className="font-medium text-gray-900 text-sm">{campaign.sequence}</p>
                            <p className="text-xs text-gray-500">{campaign.recipient}</p>
                          </div>
                        </div>
                        <div className="text-right">
                          <p className="text-xs font-medium text-emerald-600">{campaign.status}</p>
                          <p className="text-xs text-gray-500">Next step {campaign.nextStep}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              <h3 className="text-xs font-medium text-gray-500 uppercase mb-2">Message History</h3>
              <div className="border rounded-lg">
                {previousMessages.map((msg, idx) => (
                  <div key={idx} className="p-3 hover:bg-gray-50">
                    <div className="flex items-start justify-between">
                      <div className="flex items-start gap-2">
                        <span className="text-gray-400">✉️</span>
                        <div>
                          <p className="text-xs text-gray-500">{msg.email}</p>
                          <p className="font-medium text-gray-900 text-sm">{msg.subject}</p>
                          <span className="inline-block mt-1 px-1.5 py-0.5 bg-blue-100 text-blue-600 text-xs rounded">{msg.status}</span>
                        </div>
                      </div>
                      <span className="text-xs text-gray-400">{msg.date}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ) : (
            /* COMPOSE STATE */
            <div>
              <div className="flex items-center justify-between mb-4">
                <h2 className="font-semibold text-gray-900">Compose Email</h2>
                <button onClick={() => setIsComposing(false)} className="text-gray-400 hover:text-gray-600 text-lg">✕</button>
              </div>

              {/* Mode Toggle */}
              <div className="flex mb-4 bg-gray-100 rounded-lg p-1 w-fit">
                <button 
                  onClick={() => { setMode('single'); setExpandedStep(null); setEditingStep(null); setShowAIPanel(false); }}
                  className={`flex items-center gap-1.5 px-4 py-2 rounded-md text-xs font-medium transition-colors ${
                    mode === 'single' ? 'bg-white text-blue-500 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  ✉️ Single Email
                </button>
                <button 
                  onClick={() => setMode('campaign')}
                  className={`flex items-center gap-1.5 px-4 py-2 rounded-md text-xs font-medium transition-colors ${
                    mode === 'campaign' ? 'bg-white text-blue-500 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  👥 Start Campaign
                </button>
              </div>

              <div className="flex gap-4">
                {/* Left Side - Form */}
                <div className="flex-1 min-w-0">
                  {mode === 'single' ? (
                    /* SINGLE EMAIL MODE */
                    <>
                      {/* AI Panel for Single Email */}
                      {showAIPanel && (
                        <div className="mb-4 p-4 bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 rounded-lg">
                          <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center gap-2">
                              <span className="text-lg">✨</span>
                              <h3 className="font-semibold text-gray-900 text-sm">Refine with AI</h3>
                            </div>
                            <button onClick={() => setShowAIPanel(false)} className="text-gray-400 hover:text-gray-600">✕</button>
                          </div>
                          
                          <div className="space-y-3">
                            <div>
                              <label className="text-xs font-medium text-gray-600 mb-2 block">Quick actions:</label>
                              <div className="flex flex-wrap gap-2">
                                {aiQuickActions.map((action, idx) => (
                                  <button
                                    key={idx}
                                    onClick={() => handleQuickAction(action.label)}
                                    className="px-2 py-1 text-xs bg-white border border-gray-200 rounded-full hover:border-purple-300 hover:bg-purple-50 transition-colors flex items-center gap-1"
                                  >
                                    <span>{action.icon}</span> {action.label}
                                  </button>
                                ))}
                              </div>
                            </div>

                            <div>
                              <label className="text-xs font-medium text-gray-600 mb-1 block">Or describe what you want:</label>
                              <textarea
                                value={aiPrompt}
                                onChange={(e) => setAiPrompt(e.target.value)}
                                placeholder="e.g., Write a warm intro that mentions their recent episode about marketing..."
                                rows={2}
                                className="w-full px-3 py-2 text-xs border border-gray-300 rounded-lg"
                              />
                            </div>

                            <div className="flex gap-4">
                              <div>
                                <label className="text-xs font-medium text-gray-600 mb-1 block">Tone:</label>
                                <select 
                                  value={aiTone} 
                                  onChange={(e) => setAiTone(e.target.value)}
                                  className="px-2 py-1 text-xs border border-gray-300 rounded"
                                >
                                  <option value="professional">Professional</option>
                                  <option value="friendly">Friendly</option>
                                  <option value="casual">Casual</option>
                                  <option value="enthusiastic">Enthusiastic</option>
                                </select>
                              </div>
                              <div>
                                <label className="text-xs font-medium text-gray-600 mb-1 block">Length:</label>
                                <select 
                                  value={aiLength} 
                                  onChange={(e) => setAiLength(e.target.value)}
                                  className="px-2 py-1 text-xs border border-gray-300 rounded"
                                >
                                  <option value="short">Short</option>
                                  <option value="medium">Medium</option>
                                  <option value="long">Detailed</option>
                                </select>
                              </div>
                            </div>

                            <button
                              onClick={handleAIGenerate}
                              disabled={aiGenerating}
                              className={`w-full py-2 text-sm font-medium rounded-lg flex items-center justify-center gap-2 ${
                                aiGenerating 
                                  ? 'bg-gray-300 text-gray-500 cursor-not-allowed' 
                                  : 'bg-purple-500 text-white hover:bg-purple-600'
                              }`}
                            >
                              {aiGenerating ? (
                                <>
                                  <span className="animate-spin">⏳</span> Generating...
                                </>
                              ) : (
                                <>
                                  <span>✨</span> Generate Email
                                </>
                              )}
                            </button>
                          </div>
                        </div>
                      )}

                      <div className="mb-3">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Template (optional)</label>
                        <select value={selectedTemplate} onChange={(e) => setSelectedTemplate(e.target.value)} className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs">
                          {templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                      </div>

                      <div className="mb-3">
                        <label className="block text-xs font-medium text-gray-700 mb-1">To <span className="text-red-500">*</span></label>
                        <input type="email" defaultValue="dan@writeyourbookinaflash.com" className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs" />
                      </div>

                      <div className="mb-3">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Recipient Name</label>
                        <input type="text" defaultValue="Write Your Book in a Flash Podcast with Dan Janal" className="w-full px-2 py-1.5 border border-gray-300 rounded bg-gray-50 text-gray-600 text-xs" readOnly />
                      </div>

                      <div className="mb-3">
                        <div className="flex items-center justify-between mb-1">
                          <label className="text-xs font-medium text-gray-700">Subject <span className="text-red-500">*</span></label>
                        </div>
                        <input type="text" value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="Subject line..." className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs" />
                      </div>

                      <div className="mb-4">
                        <div className="flex items-center justify-between mb-1">
                          <label className="text-xs font-medium text-gray-700">Message <span className="text-red-500">*</span></label>
                          <button 
                            onClick={() => setShowAIPanel(!showAIPanel)}
                            className={`px-2 py-1 text-xs rounded border flex items-center gap-1 transition-colors ${
                              showAIPanel 
                                ? 'bg-purple-100 border-purple-300 text-purple-700' 
                                : 'bg-white border-cyan-300 text-cyan-600 hover:bg-cyan-50'
                            }`}
                          >
                            ✨ Refine with AI
                          </button>
                        </div>
                        <textarea 
                          value={message} 
                          onChange={(e) => setMessage(e.target.value)} 
                          placeholder="Write your message here..." 
                          rows={10} 
                          className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs resize-y" 
                        />
                      </div>

                      {/* Action Buttons Row */}
                      <div className="flex items-center justify-between pt-4 border-t">
                        <div className="flex items-center gap-2">
                          <button 
                            onClick={handleOpenInEmail}
                            className="px-3 py-1.5 text-xs border border-gray-300 text-gray-700 rounded hover:bg-gray-50 flex items-center gap-1"
                          >
                            📧 Open in Email
                          </button>
                          <button 
                            onClick={handleCopyBody}
                            className={`px-3 py-1.5 text-xs border rounded flex items-center gap-1 transition-colors ${
                              copied 
                                ? 'border-green-300 bg-green-50 text-green-700' 
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
                            }`}
                          >
                            {copied ? '✓ Copied!' : '📋 Copy Body'}
                          </button>
                          <button 
                            onClick={handleSaveDraft}
                            className="px-3 py-1.5 text-xs border border-gray-300 text-gray-700 rounded hover:bg-gray-50 flex items-center gap-1"
                          >
                            💾 Save Draft
                          </button>
                        </div>
                        <div className="flex items-center gap-2">
                          <button onClick={() => setIsComposing(false)} className="px-3 py-1.5 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 text-xs">
                            Cancel
                          </button>
                          <button 
                            onClick={handleMarkAsSent}
                            className="px-4 py-1.5 bg-teal-600 text-white rounded hover:bg-teal-700 text-xs flex items-center gap-1"
                          >
                            ✓ Mark as Sent
                          </button>
                        </div>
                      </div>
                    </>
                  ) : (
                    /* CAMPAIGN MODE */
                    <>
                      <div className="mb-3">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Select Sequence <span className="text-red-500">*</span></label>
                        <select 
                          value={selectedSequence} 
                          onChange={(e) => { setSelectedSequence(e.target.value); setExpandedStep(null); setEditingStep(null); setStepEdits({}); setShowAIPanel(false); }} 
                          className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs"
                        >
                          {sequences.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                      </div>

                      {/* Campaign Steps */}
                      <div className="mb-4 rounded-lg border overflow-hidden">
                        <div className="bg-gray-50 px-3 py-2 border-b flex items-center justify-between">
                          <p className="text-xs font-medium text-gray-700">Campaign Steps</p>
                          <p className="text-xs text-gray-500">Click step to preview</p>
                        </div>
                        <div className="divide-y">
                          {currentSequence?.steps.map((step, idx) => (
                            <div key={idx}>
                              {/* Step Header */}
                              <button 
                                onClick={() => toggleStep(idx)}
                                className={`w-full px-3 py-2.5 flex items-center justify-between hover:bg-gray-50 transition-colors ${expandedStep === idx ? 'bg-blue-50' : ''}`}
                              >
                                <div className="flex items-center gap-3">
                                  <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-medium ${
                                    expandedStep === idx ? 'bg-blue-500 text-white' : idx === 0 ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600'
                                  }`}>
                                    {idx + 1}
                                  </div>
                                  <div className="text-left">
                                    <span className="text-xs font-medium text-gray-900">{step.name}</span>
                                    {step.delay && <span className="ml-2 text-xs text-cyan-500">{step.delay}</span>}
                                    {stepEdits[idx] && <span className="ml-2 text-xs text-orange-500">(customized)</span>}
                                  </div>
                                </div>
                                <span className={`text-gray-400 transition-transform ${expandedStep === idx ? 'rotate-180' : ''}`}>▼</span>
                              </button>
                              
                              {/* Expanded Step Content */}
                              {expandedStep === idx && (
                                <div className="px-3 py-3 bg-gray-50 border-t">
                                  <div className="bg-white rounded-lg border p-3">
                                    {editingStep === idx ? (
                                      /* EDIT MODE */
                                      <>
                                        {/* AI Panel for Campaign Step */}
                                        {showAIPanel && (
                                          <div className="mb-4 p-3 bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 rounded-lg">
                                            <div className="flex items-center justify-between mb-3">
                                              <div className="flex items-center gap-2">
                                                <span>✨</span>
                                                <h3 className="font-semibold text-gray-900 text-xs">Refine with AI</h3>
                                              </div>
                                              <button onClick={() => setShowAIPanel(false)} className="text-gray-400 hover:text-gray-600 text-sm">✕</button>
                                            </div>
                                            
                                            <div className="space-y-2">
                                              <div className="flex flex-wrap gap-1.5">
                                                {aiQuickActions.slice(0, 4).map((action, idx) => (
                                                  <button
                                                    key={idx}
                                                    onClick={() => handleQuickAction(action.label)}
                                                    className="px-2 py-1 text-xs bg-white border border-gray-200 rounded-full hover:border-purple-300 hover:bg-purple-50 flex items-center gap-1"
                                                  >
                                                    <span>{action.icon}</span> {action.label}
                                                  </button>
                                                ))}
                                              </div>
                                              
                                              <textarea
                                                value={aiPrompt}
                                                onChange={(e) => setAiPrompt(e.target.value)}
                                                placeholder="Or describe what you want..."
                                                rows={2}
                                                className="w-full px-2 py-1.5 text-xs border border-gray-300 rounded"
                                              />
                                              
                                              <button
                                                onClick={handleAIGenerate}
                                                disabled={aiGenerating}
                                                className={`w-full py-1.5 text-xs font-medium rounded flex items-center justify-center gap-1 ${
                                                  aiGenerating ? 'bg-gray-300 text-gray-500' : 'bg-purple-500 text-white hover:bg-purple-600'
                                                }`}
                                              >
                                                {aiGenerating ? '⏳ Generating...' : '✨ Generate'}
                                              </button>
                                            </div>
                                          </div>
                                        )}

                                        <div className="flex items-center justify-between mb-2">
                                          <label className="text-xs text-gray-500 font-medium">Subject:</label>
                                          <button 
                                            onClick={() => setShowAIPanel(!showAIPanel)}
                                            className={`px-2 py-0.5 text-xs rounded border flex items-center gap-1 ${
                                              showAIPanel 
                                                ? 'bg-purple-100 border-purple-300 text-purple-700' 
                                                : 'bg-white border-cyan-300 text-cyan-600 hover:bg-cyan-50'
                                            }`}
                                          >
                                            ✨ Refine with AI
                                          </button>
                                        </div>
                                        <input 
                                          type="text"
                                          value={getStepContent(idx).subject}
                                          onChange={(e) => setStepEdits(prev => ({
                                            ...prev,
                                            [idx]: { ...prev[idx], subject: e.target.value }
                                          }))}
                                          className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs mb-2"
                                        />
                                        
                                        <label className="text-xs text-gray-500 font-medium">Message:</label>
                                        <textarea 
                                          value={getStepContent(idx).body}
                                          onChange={(e) => setStepEdits(prev => ({
                                            ...prev,
                                            [idx]: { ...prev[idx], body: e.target.value }
                                          }))}
                                          rows={8}
                                          className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs mt-1 font-mono resize-y"
                                        />
                                        
                                        {/* Save Options */}
                                        <div className="pt-3 border-t space-y-2 mt-3">
                                          <div className="flex items-center gap-2">
                                            <button onClick={cancelEditingStep} className="px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 rounded">
                                              Cancel
                                            </button>
                                            <button 
                                              onClick={() => { setEditingStep(null); setShowAIPanel(false); }} 
                                              className="px-3 py-1 text-xs text-white bg-blue-500 hover:bg-blue-600 rounded"
                                            >
                                              ✓ Save for this recipient only
                                            </button>
                                            <button 
                                              onClick={() => {
                                                setStepEdits(prev => { const n = { ...prev }; delete n[idx]; return n; });
                                                setEditingStep(null);
                                                setPreviewMode(true);
                                                setShowAIPanel(false);
                                              }}
                                              className="px-2 py-1 text-xs text-red-500 hover:bg-red-50 rounded ml-auto"
                                            >
                                              Reset
                                            </button>
                                          </div>
                                          
                                          <div className="flex items-center gap-2 pt-2 border-t border-dashed">
                                            <span className="text-xs text-gray-500">Save to template:</span>
                                            <button 
                                              onClick={() => openSaveModal('update')}
                                              className="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded border border-orange-200 flex items-center gap-1"
                                            >
                                              💾 Update "{step.name}"
                                            </button>
                                            <button 
                                              onClick={() => openSaveModal('new')}
                                              className="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded border border-green-200 flex items-center gap-1"
                                            >
                                              ➕ Save as New Template
                                            </button>
                                          </div>
                                        </div>
                                      </>
                                    ) : (
                                      /* PREVIEW MODE */
                                      <>
                                        <div className="flex items-center justify-between mb-3">
                                          <div className="flex bg-gray-100 rounded p-0.5">
                                            <button 
                                              onClick={() => setPreviewMode(true)}
                                              className={`px-2 py-1 text-xs rounded transition-colors ${previewMode ? 'bg-white shadow-sm text-blue-600 font-medium' : 'text-gray-500'}`}
                                            >
                                              👁️ Preview
                                            </button>
                                            <button 
                                              onClick={() => setPreviewMode(false)}
                                              className={`px-2 py-1 text-xs rounded transition-colors ${!previewMode ? 'bg-white shadow-sm text-blue-600 font-medium' : 'text-gray-500'}`}
                                            >
                                              {'{ }'} Template
                                            </button>
                                          </div>
                                          {previewMode && (
                                            <span className="text-xs text-green-600 flex items-center gap-1">
                                              ✓ All variables resolved
                                            </span>
                                          )}
                                        </div>

                                        <div className="mb-2">
                                          <label className="text-xs text-gray-500">Subject:</label>
                                          <p className="text-xs font-medium text-gray-900 mt-0.5">
                                            {previewMode ? resolveVariables(getStepContent(idx).subject) : getStepContent(idx).subject}
                                          </p>
                                        </div>
                                        <div className="mb-3">
                                          <label className="text-xs text-gray-500">Message:</label>
                                          <pre className={`text-xs mt-1 whitespace-pre-wrap font-sans p-2 rounded border max-h-48 overflow-y-auto ${previewMode ? 'bg-green-50 border-green-200 text-gray-800' : 'bg-gray-50 text-gray-700'}`}>
                                            {previewMode ? resolveVariables(getStepContent(idx).body) : getStepContent(idx).body}
                                          </pre>
                                        </div>
                                        <div className="flex items-center gap-2 pt-2 border-t">
                                          <button 
                                            onClick={() => startEditingStep(idx)}
                                            className="px-2 py-1 text-xs text-blue-500 hover:bg-blue-50 rounded flex items-center gap-1"
                                          >
                                            ✏️ Customize for this recipient
                                          </button>
                                          <span className="text-xs text-gray-400">|</span>
                                          <button className="px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 rounded">
                                            Edit template →
                                          </button>
                                        </div>
                                      </>
                                    )}
                                  </div>
                                </div>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>

                      <div className="mb-3">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Recipient Email <span className="text-red-500">*</span></label>
                        <input type="email" defaultValue="dan@writeyourbookinaflash.com" className="w-full px-2 py-1.5 border border-gray-300 rounded text-xs" />
                      </div>

                      <div className="mb-4">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Recipient Name</label>
                        <input type="text" defaultValue="Write Your Book in a Flash Podcast with Dan Janal" className="w-full px-2 py-1.5 border border-gray-300 rounded bg-gray-50 text-gray-600 text-xs" readOnly />
                      </div>

                      {/* Action Buttons Row for Campaign */}
                      <div className="flex items-center justify-between pt-4 border-t">
                        <div className="flex items-center gap-2">
                          <button 
                            onClick={handleSaveDraft}
                            className="px-3 py-1.5 text-xs border border-gray-300 text-gray-700 rounded hover:bg-gray-50 flex items-center gap-1"
                          >
                            💾 Save Draft
                          </button>
                        </div>
                        <div className="flex items-center gap-2">
                          <button onClick={() => setIsComposing(false)} className="px-3 py-1.5 border border-gray-300 text-gray-700 rounded hover:bg-gray-50 text-xs">
                            Cancel
                          </button>
                          <button className="px-4 py-1.5 bg-teal-600 text-white rounded hover:bg-teal-700 text-xs flex items-center gap-1">
                            ▶ Start Campaign
                            {Object.keys(stepEdits).length > 0 && (
                              <span className="ml-1 px-1.5 py-0.5 bg-teal-700 rounded text-xs">{Object.keys(stepEdits).length} customized</span>
                            )}
                          </button>
                        </div>
                      </div>
                    </>
                  )}
                </div>

                {/* Right Side - Variables Sidebar */}
                {showVariablesSidebar && (
                  <div className="w-64 border-l pl-4 flex-shrink-0">
                    <div className="sticky top-4">
                      <div className="mb-3">
                        <h3 className="font-semibold text-gray-900 text-xs">Personalization</h3>
                        <p className="text-xs text-gray-500">Click to insert variable tag</p>
                      </div>
                      
                      {mode === 'campaign' && editingStep === null && expandedStep !== null && (
                        <div className="mb-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-700">
                          💡 Click "Customize" to edit and insert variables
                        </div>
                      )}
                      
                      {mode === 'campaign' && editingStep !== null && (
                        <div className="mb-3 p-2 bg-green-50 border border-green-200 rounded text-xs text-green-700">
                          ✏️ Editing Step {editingStep + 1} — click to insert
                        </div>
                      )}
                      
                      <div className="relative mb-3">
                        <input type="text" placeholder="Search variables..." className="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg pl-8" />
                        <span className="absolute left-2.5 top-2 text-gray-400 text-xs">🔍</span>
                      </div>

                      <div className="max-h-96 overflow-y-auto pr-1">
                        {Object.entries(variables).map(([section, vars]) => (
                          <div key={section} className="mb-3">
                            <button onClick={() => toggleSection(section)} className="flex items-center justify-between w-full text-left py-1.5 text-xs font-medium text-gray-700">
                              <div className="flex items-center gap-1">
                                <span className={`transition-transform text-xs ${expandedSections[section] ? '' : '-rotate-90'}`}>▼</span>
                                {section === 'messaging' && 'Messaging & Positioning'}
                                {section === 'podcast' && 'Podcast Info'}
                                {section === 'contact' && 'Contact Info'}
                              </div>
                              <span className="text-gray-400 text-xs">{vars.length}</span>
                            </button>
                            
                            {expandedSections[section] && (
                              <div className="space-y-1.5 mt-1">
                                {vars.map((v) => (
                                  <div 
                                    key={v.key} 
                                    onClick={() => insertVariable(v.key)} 
                                    className={`p-2 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 cursor-pointer transition-colors ${
                                      mode === 'campaign' && editingStep === null ? 'opacity-50 cursor-not-allowed' : ''
                                    }`}
                                  >
                                    <p className="text-xs font-medium text-gray-700">{v.label}</p>
                                    <code className="text-xs text-cyan-500 font-mono">{`{{${v.key}}}`}</code>
                                    <p className="text-xs text-gray-400 mt-0.5 truncate">{v.preview}</p>
                                  </div>
                                ))}
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                )}
              </div>

              <div className="mt-6 pt-4 border-t">
                <button className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700">
                  ▶ Previous Messages ({previousMessages.length})
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default MessageTabDualModeMockup;
