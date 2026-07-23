// Display names and grouping for prediction market keys.

export const MARKET_LABELS = {
    result: 'Match result',
    goals: 'Total goals',
    btts: 'Both teams to score',
    team_goals_home: 'Home team goals',
    team_goals_away: 'Away team goals',
    corners: 'Total corners',
    team_corners_home: 'Home team corners',
    team_corners_away: 'Away team corners',
    cards: 'Total cards',
    shots_on_target: 'Total shots on target',
    team_sot_home: 'Home team shots on target',
    team_sot_away: 'Away team shots on target',
};

// Section layout for the match-detail page (spec 8.2).
export const MARKET_SECTIONS = [
    { title: 'Match Result', markets: ['result'] },
    { title: 'Goals', markets: ['goals', 'btts', 'team_goals_home', 'team_goals_away'] },
    { title: 'Corners', markets: ['corners', 'team_corners_home', 'team_corners_away'] },
    { title: 'Cards', markets: ['cards'] },
    { title: 'Shots on Target', markets: ['shots_on_target', 'team_sot_home', 'team_sot_away'] },
];

export function marketLabel(market) {
    return MARKET_LABELS[market] ?? market;
}

// Team names are optional: without them the side prefix is omitted (used
// where the market label already says "Home team ...").
export function lineLabel(row, homeName = null, awayName = null) {
    if (row.market === 'result') {
        if (row.direction === 'draw') return 'Draw';
        const side = row.direction === 'home' ? homeName || 'Home' : awayName || 'Away';
        return `${side} win`;
    }
    if (row.market === 'btts') {
        return row.direction === 'yes' ? 'Yes' : 'No';
    }
    const side = row.market.endsWith('_home') && homeName
        ? `${homeName} `
        : row.market.endsWith('_away') && awayName
          ? `${awayName} `
          : '';
    const word = row.direction === 'over' ? 'Over' : 'Under';
    return `${side}${word} ${row.line}`;
}
